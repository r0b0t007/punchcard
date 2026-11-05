<?php

declare(strict_types=1);

namespace App\Actions\Automations;

use App\Actions\Campaigns\DeliverCampaign;
use App\Actions\Campaigns\DescribeAudience;
use App\Enums\AutomationTrigger;
use App\Enums\CustomerSegment;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Features\Automations;
use App\Jobs\PushAutomationMessages;
use App\Models\Automation;
use App\Models\AutomationSend;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;
use App\Models\Follow;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\LocalMoment;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * The scheduler's pass over automations (CHW-46), hourly. For each business
 * allowed automations (App\Features\Automations) with one turned on, open
 * and not suspended, between 08:00 and 22:00 in its own time:
 *
 * - who each automation reaches now, with a phone:
 *   - reward reminder: a reward still available, unlocked here (its stamp
 *     was given here) at least the automation's days ago, to a customer
 *     with "Rewards ready" on; once per reward;
 *   - win-back: a follower who came in (Follow::listeningTo) and is going
 *     quiet here (BusinessCustomer, CustomerSegment::Quiet); once per quiet
 *     spell, which a visit ends;
 * - at most one message per customer per business in WEEK days, across
 *   every automation: a reward reminder first, then a win-back. A message
 *   held back by it is not recorded, so it is tried again later;
 * - each is claimed first (automation_sends, unique per automation, customer
 *   and occasion), so a run again never sends it twice, then queued in the
 *   business's tenant (PushAutomationMessages), in one transaction with its
 *   claims. As it goes, each is checked again (SendAutomationMessages).
 *
 * Never an anonymised account. A business that fails is logged by id and
 * left to the next run; the others go on. Reads in bypass(), each kept to
 * the business and its organization.
 */
final readonly class RunAutomations
{
    public const int WEEK = 7;

    public const int OPENS = 8;

    public const int CLOSES = 22;

    /** A claim not sent within this many minutes is not sent: its moment has passed. */
    public const int LATE_MINUTES = 60;

    /** Automations in the order the weekly cap serves them. */
    private const array ORDER = [AutomationTrigger::RewardReminder, AutomationTrigger::WinBack];

    public function __construct(private TenantContext $context) {}

    /** @return array{businesses: int, claimed: int} */
    public function handle(CarbonImmutable $now): array
    {
        $count = ['businesses' => 0, 'claimed' => 0];
        $businesses = $this->context->bypass(fn () => Business::query()->with('organization')
            ->whereIn('id', Automation::query()->select('business_id')->where('active', true))
            ->orderBy('id')->get());

        foreach ($businesses as $business) {
            try {
                if (! $this->context->bypass(fn (): bool => self::sendsNow($business, $now))) {
                    continue;
                }

                // The claims and their jobs commit together (the database queue): a dispatch failing leaves
                // nothing claimed, so the next run sends it.
                $claimed = DB::transaction(function () use ($business, $now): array {
                    $claimed = $this->context->bypass(fn (): array => $this->claim($business, $now));
                    $this->queue($business, $claimed);

                    return $claimed;
                });
            } catch (Throwable $failed) {
                // The others go on; the next run takes this one up. Never the message.
                Log::warning('The automations skipped a business.', ['business_id' => $business->id, 'error' => $failed::class]);

                continue;
            }

            $count['businesses']++;
            $count['claimed'] += count($claimed);
        }

        return $count;
    }

    /**
     * Whether the business sends now: allowed automations, open and not
     * suspended, between OPENS and CLOSES in its own time. Read by the run,
     * and again as each message goes (SendAutomationMessages). Outside any
     * transaction: Pennant's first read of a business inserts a row.
     */
    public static function sendsNow(Business $business, CarbonInterface $now): bool
    {
        $hour = $now->copy()->setTimezone(LocalMoment::timezoneOfBusiness($business->id))->hour;

        return $hour >= self::OPENS && $hour < self::CLOSES
            && ArchivedSites::isOpen($business->id, null, counter: true)
            && Feature::for($business)->active(Automations::class);
    }

    /**
     * Claims the business's messages due now.
     *
     * @return list<int> the sends claimed
     */
    private function claim(Business $business, CarbonImmutable $now): array
    {
        $automations = Automation::query()->where('business_id', $business->id)->where('active', true)->get()->keyBy(fn (Automation $automation): string => $automation->trigger->value);
        // Who already had a message from this business this week: the cap.
        $served = array_flip(AutomationSend::query()->where('business_id', $business->id)
            ->where('created_at', '>', $now->subDays(self::WEEK))->distinct()->pluck('user_id')->all());
        $claimed = [];

        foreach (self::ORDER as $trigger) {
            $automation = $automations->get($trigger->value);

            if (! $automation instanceof Automation) {
                continue;
            }

            foreach ($this->due($automation, $business, $now) as $userId => $occasion) {
                if (isset($served[$userId])) {
                    continue;
                }

                try {
                    // A savepoint: a claim refused as a duplicate never aborts what holds it on Postgres. The
                    // customer's row is held first, as DeleteAccount takes it: an account anonymised meanwhile
                    // is never claimed, and one anonymised after loses this send with the rest.
                    $send = DB::transaction(fn (): ?AutomationSend => User::query()->whereKey($userId)->notAnonymised()->sharedLock()->exists()
                        ? AutomationSend::query()->create([
                            'business_id' => $business->id, 'automation_id' => $automation->id, 'user_id' => $userId,
                            'trigger' => $trigger, 'occasion' => $occasion,
                        ])
                        : null);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }

                if (! $send instanceof AutomationSend) {
                    continue;
                }

                $served[$userId] = true;
                $claimed[] = $send->id;
            }
        }

        return $claimed;
    }

    /**
     * Who the automation reaches now, with a phone, and for what occasion:
     * the first one it has not reached them for yet.
     *
     * @return array<int, string> occasion by user id
     */
    private function due(Automation $automation, Business $business, CarbonImmutable $now): array
    {
        $candidates = match ($automation->trigger) {
            AutomationTrigger::RewardReminder => $this->rewardsWaiting($automation, $business, $now),
            AutomationTrigger::WinBack => BusinessCustomer::query()->where('business_id', $business->id)
                ->inSegment(CustomerSegment::Quiet, $now)
                ->whereIn('user_id', Follow::query()->listeningTo($business)->select('user_id'))
                ->orderBy('user_id')->get(['user_id', 'quiet_at'])
                ->map(fn (BusinessCustomer $customer): array => [$customer->user_id, 'quiet:'.$customer->quiet_at->toDateString()])->all(),
            AutomationTrigger::Birthday => [],
        };
        $withPhone = array_flip(DescribeAudience::withPhone(array_values(array_unique(array_column($candidates, 0)))));
        $sent = [];

        foreach (array_chunk(array_keys($withPhone), 500) as $userIds) {
            foreach (AutomationSend::query()->where('automation_id', $automation->id)->whereIn('user_id', $userIds)->get(['user_id', 'occasion']) as $send) {
                $sent[$send->user_id.'|'.$send->occasion] = true;
            }
        }

        $due = [];

        foreach ($candidates as [$userId, $occasion]) {
            if (isset($withPhone[$userId]) && ! isset($due[$userId]) && ! isset($sent[$userId.'|'.$occasion])) {
                $due[$userId] = $occasion;
            }
        }

        return $due;
    }

    /**
     * Rewards still available, unlocked here at least the automation's days
     * ago, of customers with "Rewards ready" on: the oldest first.
     *
     * @return list<array{0: int, 1: string}> user id and occasion
     */
    private function rewardsWaiting(Automation $automation, Business $business, CarbonImmutable $now): array
    {
        $rewards = Reward::query()
            ->where('organization_id', $business->organization_id)
            ->where('status', RewardStatus::Available)
            ->where('unlocked_at', '<=', $now->subDays($automation->days))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            // Unlocked by a visit here: a stamp given elsewhere for them (a referral) never makes them this business's.
            ->whereIn('stamp_event_id', StampEvent::query()->select('id')->where('business_id', $business->id)->whereIn('source', StampSource::presenceValues()))
            ->orderBy('unlocked_at')->orderBy('id')
            ->get(['id', 'enrollment_id']);
        $owners = CardEnrollment::query()->whereKey($rewards->pluck('enrollment_id')->unique()->all())
            ->whereIn('user_id', User::query()->select('id')->notAnonymised()->where('push_rewards', true))
            ->pluck('user_id', 'id');
        $waiting = [];

        foreach ($rewards as $reward) {
            $userId = $owners->get($reward->enrollment_id);

            if (is_int($userId)) {
                $waiting[] = [$userId, 'reward:'.$reward->id];
            }
        }

        return $waiting;
    }

    /** @param list<int> $sendIds */
    private function queue(Business $business, array $sendIds): void
    {
        if ($sendIds === []) {
            return;
        }

        $previous = $this->context->snapshot();

        try {
            $this->context->set($business->organization, $business);

            foreach (array_chunk($sendIds, DeliverCampaign::BATCH) as $batch) {
                PushAutomationMessages::dispatch($batch);
            }
        } finally {
            $this->context->restore($previous);
        }
    }
}
