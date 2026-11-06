<?php

declare(strict_types=1);

namespace App\Actions\Automations;

use App\Actions\Campaigns\DeliverCampaign;
use App\Actions\Campaigns\DescribeAudience;
use App\Enums\AutomationTrigger;
use App\Enums\CustomerSegment;
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
use App\Support\Automations\AutomationWindow;
use App\Support\Automations\BirthdayKey;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The scheduler's pass over automations (CHW-46), hourly. For each business
 * allowed automations (App\Features\Automations) with one turned on, open
 * and not suspended, between 08:00 and 22:00 in its own time
 * (AutomationWindow):
 *
 * - the birthday bonus first (GiveBirthdayStamps), whatever the messages;
 * - who each automation reaches now, with a phone:
 *   - reward reminder: a reward still available, unlocked here by a visit
 *     at least the automation's days ago, to a customer following the
 *     business with notifications on and "Rewards ready" on; once per
 *     reward;
 *   - birthday: a follower who came in, whose birthday it is today and
 *     whose bonus this business gave this year; once a year;
 *   - win-back: a follower who came in (Follow::listeningTo) and is going
 *     quiet here (BusinessCustomer, CustomerSegment::Quiet); once per quiet
 *     spell, which a visit ends;
 * - at most one message per customer per business in WEEK days, across
 *   every automation: a reward reminder first, then a birthday, then a
 *   win-back. A message held back by it is not recorded, so it is tried
 *   again later (a birthday's, until its day ends);
 * - each is claimed first (automation_sends, unique per automation, customer
 *   and occasion), so a run again never sends it twice, then queued in the
 *   business's tenant (PushAutomationMessages), in one transaction with its
 *   claims. As it goes, each is checked again (SendAutomationMessages). One
 *   never sent is let go after AutomationWindow::RELEASE_MINUTES, so a later
 *   run claims it again if it still holds.
 *
 * Never an anonymised account. A business that fails is logged by id and
 * left to the next run; the others go on. Reads in bypass(), each kept to
 * the business and its organization.
 */
final readonly class RunAutomations
{
    public const int WEEK = 7;

    /** Automations in the order the weekly cap serves them. */
    private const array ORDER = [AutomationTrigger::RewardReminder, AutomationTrigger::Birthday, AutomationTrigger::WinBack];

    public function __construct(private TenantContext $context, private GiveBirthdayStamps $giveBirthdayStamps) {}

    /** @return array{businesses: int, claimed: int, birthday_stamps: int} */
    public function handle(CarbonImmutable $now): array
    {
        $count = ['businesses' => 0, 'claimed' => 0, 'birthday_stamps' => 0];
        $businesses = $this->context->bypass(fn () => Business::query()->with('organization')
            ->whereIn('id', Automation::query()->select('business_id')->where('active', true))
            ->orderBy('id')->get());

        foreach ($businesses as $business) {
            try {
                if (! $this->context->bypass(fn (): bool => AutomationWindow::sendsNow($business, $now))) {
                    continue;
                }

                // The business's day, and its automations turned on, read once for the run.
                [$today, $automations] = $this->context->bypass(fn (): array => [
                    $now->setTimezone(LocalMoment::timezoneOfBusiness($business->id)),
                    Automation::query()->where('business_id', $business->id)->where('active', true)->get()
                        ->keyBy(fn (Automation $automation): string => $automation->trigger->value),
                ]);
                $birthday = $automations->get(AutomationTrigger::Birthday->value);

                // The birthday bonus first, each stamp its own transaction: its message is claimed below. Its
                // own try: the business's other messages never wait on it.
                if ($birthday instanceof Automation) {
                    try {
                        $count['birthday_stamps'] += $this->giveBirthdayStamps->handle($business, $birthday, $today);
                    } catch (Throwable $failed) {
                        Log::warning('The birthday bonus skipped a business.', ['business_id' => $business->id, 'error' => $failed::class]);
                    }
                }

                // The claims and their jobs commit together on the database queue (the app's, CLAUDE.md): a
                // dispatch failing leaves nothing claimed, so the next run sends it. On the sync queue (local
                // only) the jobs run inside, before the commit.
                $claimed = DB::transaction(function () use ($business, $now, $today, $automations): array {
                    $claimed = $this->context->bypass(fn (): array => $this->claim($business, $now, $today, $automations));
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
     * Claims the business's messages due now, after letting go of those
     * claimed long ago and never sent (a queue too late, a push refused):
     * their occasion is claimed again if it still holds, and they no longer
     * count toward the cap.
     *
     * @param  CarbonImmutable  $today  now, in the business's time
     * @param  Collection<string, Automation>  $automations  its automations turned on, by trigger
     * @return list<int> the sends claimed
     */
    private function claim(Business $business, CarbonImmutable $now, CarbonImmutable $today, Collection $automations): array
    {
        AutomationSend::query()->where('business_id', $business->id)->whereNull('sent_at')
            ->where('created_at', '<=', $now->subMinutes(AutomationWindow::RELEASE_MINUTES))->delete();

        // Who already had a message from this business this week: the cap.
        $served = array_flip(AutomationSend::query()->where('business_id', $business->id)
            ->where('created_at', '>', $now->subDays(self::WEEK))->distinct()->pluck('user_id')->all());
        $claimed = [];

        foreach (self::ORDER as $trigger) {
            $automation = $automations->get($trigger->value);

            if (! $automation instanceof Automation) {
                continue;
            }

            foreach ($this->due($automation, $business, $now, $today) as $userId => $occasion) {
                if (isset($served[$userId])) {
                    continue;
                }

                try {
                    // A savepoint: a claim refused as a duplicate never aborts what holds it on Postgres. The
                    // customer's row is held first, as DeleteAccount takes it: an account anonymised meanwhile
                    // is never claimed, and one anonymised after loses this send with the rest.
                    $send = DB::transaction(fn (): ?AutomationSend => User::query()->whereKey($userId)->notAnonymised()->lock('for key share')->exists()
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
    private function due(Automation $automation, Business $business, CarbonImmutable $now, CarbonImmutable $today): array
    {
        $candidates = match ($automation->trigger) {
            AutomationTrigger::RewardReminder => $this->rewardsWaiting($automation, $business, $now),
            AutomationTrigger::WinBack => BusinessCustomer::query()->where('business_id', $business->id)
                ->inSegment(CustomerSegment::Quiet, $now)
                ->whereIn('user_id', Follow::query()->listeningTo($business)->select('user_id'))
                ->orderBy('user_id')->get(['user_id', 'quiet_at'])
                ->map(fn (BusinessCustomer $customer): array => [$customer->user_id, 'quiet:'.$customer->quiet_at->toDateString()])->all(),
            AutomationTrigger::Birthday => $this->birthdaysToday($business, $today),
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
     * ago, of customers following the business with
     * notifications on (Follow::listeningTo) and "Rewards ready" on: the
     * oldest first.
     *
     * @return list<array{0: int, 1: string}> user id and occasion
     */
    private function rewardsWaiting(Automation $automation, Business $business, CarbonImmutable $now): array
    {
        $rewardsHere = Reward::query()
            ->where('organization_id', $business->organization_id)
            ->availableAt($now)
            ->where('unlocked_at', '<=', $now->subDays($automation->days))
            // Unlocked here by a visit, or by this business's birthday bonus: a stamp given elsewhere for them (a
            // referral) never makes them this business's.
            ->whereIn('stamp_event_id', StampEvent::query()->select('id')->where('business_id', $business->id)
                ->whereIn('source', [...StampSource::presenceValues(), StampSource::Birthday->value]));
        // A subquery: a chain's waiting rewards would pass a database's bound parameters as a list.
        $owners = CardEnrollment::query()->whereIn('id', (clone $rewardsHere)->select('enrollment_id'))
            ->whereIn('user_id', User::query()->select('id')->notAnonymised()->where('push_rewards', true))
            ->whereIn('user_id', Follow::query()->listeningTo($business)->select('user_id'))
            ->pluck('user_id', 'id');
        $rewards = $rewardsHere->orderBy('unlocked_at')->orderBy('id')->get(['id', 'enrollment_id']);
        $waiting = [];

        foreach ($rewards as $reward) {
            $userId = $owners->get($reward->enrollment_id);

            if (is_int($userId)) {
                $waiting[] = [$userId, 'reward:'.$reward->id];
            }
        }

        return $waiting;
    }

    /**
     * Whose birthday it is today in the business's time, among the
     * followers who came in (Follow::listeningTo), given this year's bonus
     * here today (GiveBirthdayStamps): the bonus is the business's that gave
     * it, and the message is for the day it was given.
     *
     * @param  CarbonImmutable  $today  now, in the business's time
     * @return list<array{0: int, 1: string}> user id and occasion
     */
    private function birthdaysToday(Business $business, CarbonImmutable $today): array
    {
        $userIds = User::query()->notAnonymised()->birthdayOn($today)
            ->whereIn('id', Follow::query()->listeningTo($business)->select('user_id'))
            ->whereIn('id', CardEnrollment::query()->select('user_id')->where('organization_id', $business->organization_id)
                ->whereHas('stampEvents', fn (Builder $events) => $events->birthdayBonusGivenToday($business->id, $today)))
            ->orderBy('id')->pluck('id')->all();

        return array_values(array_map(fn (int $userId): array => [$userId, BirthdayKey::occasion($today->year)], $userIds));
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
