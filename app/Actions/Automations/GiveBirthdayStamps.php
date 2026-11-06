<?php

declare(strict_types=1);

namespace App\Actions\Automations;

use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRejected;
use App\Actions\Stamps\StampRequest;
use App\Enums\StampRejection;
use App\Enums\StampSource;
use App\Models\Automation;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Automations\Birthday;
use App\Support\Automations\BirthdayKey;
use App\Support\Database\Outermost;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The birthday bonus (CHW-46): the automation's stamps (1 to 3) on each card
 * of the organization this business honours, for its customers (a visit
 * here, BusinessCustomer) whose birthday is today in its time
 * (User::birthdayOn: 29 February on 28 February of a year without one),
 * given 30 days before at least (User::birthdayOnFileFor, CHW-370): one set
 * today, or moved to today, gets nothing. Never an anonymised account. No
 * follow or phone needed: the stamps are the treat, the message is
 * RunAutomations'.
 *
 * Once a year per card, across the organization, and 365 days apart at
 * least by the date each was given in its site's time
 * (Birthday::bonusTooRecent, CHW-370), so a birthday moved past the turn of
 * the year collects nothing: each is a system stamp keyed per card and year
 * (BirthdayKey), the year of that birthday. A card with that key at any of
 * its businesses is left out in the query; under the card's lock, which
 * AddStamps takes too, so two runs never both give it, so is one with a
 * bonus too recent, read with its site's timezone; the database holds the
 * key as well (stamp_events_birthday_once). In a franchise the first business to reach
 * a card gives it. Credited to the business's first open site, in the
 * business's tenant (as a tap is, so what follows, a reward unlocked and
 * pushed, is queued there), in a transaction run again if a deadlock aborts
 * it (Outermost). An account deleted meanwhile is left out; any other
 * refusal or failure is logged by card id, and the others go on.
 *
 * Called by RunAutomations, outside any transaction, only while the business
 * sends (AutomationWindow::sendsNow).
 */
final readonly class GiveBirthdayStamps
{
    public function __construct(private AddStamps $addStamps, private TenantContext $context) {}

    /**
     * @param  CarbonImmutable  $today  now, in the business's time
     * @return int the cards stamped
     */
    public function handle(Business $business, Automation $automation, CarbonImmutable $today): int
    {
        [$site, $enrollments] = $this->context->bypass(fn (): array => [
            Location::query()->where('business_id', $business->id)->open()->orderBy('id')->first(),
            CardEnrollment::query()
                ->where('organization_id', $business->organization_id)
                ->whereIn('user_id', User::query()->select('id')->notAnonymised()->birthdayOn($today)->birthdayOnFileFor($today))
                ->whereIn('user_id', BusinessCustomer::query()->select('user_id')->where('business_id', $business->id))
                ->whereHas('card', fn (Builder $cards) => $cards->where('active', true)->whereHas('businesses', fn (Builder $businesses) => $businesses->whereKey($business->id)))
                // Given this year already, here or elsewhere: no lock needed to leave it out. One too recent, under the lock.
                ->whereDoesntHave('stampEvents', fn (Builder $events) => $events->where('source', StampSource::Birthday)
                    ->where('idempotency_key', 'like', BirthdayKey::ofYear($today->year)))
                ->orderBy('id')
                ->get(),
        ]);

        if (! $site instanceof Location) {
            return 0;
        }

        $previous = $this->context->snapshot();
        $stamped = 0;

        try {
            $this->context->set($business->organization, $business);

            foreach ($enrollments as $enrollment) {
                $stamped += $this->give($enrollment, $site, $automation, $today) ? 1 : 0;
            }
        } finally {
            $this->context->restore($previous);
        }

        return $stamped;
    }

    /**
     * Whether a card had a birthday bonus that keeps it from another today: this year's (its key, whenever it was
     * written), or one given less than BONUS_GAP_DAYS before by its date in its own site's time, a moved birthday's.
     */
    private function hadBonusLately(int $enrollmentId, CarbonImmutable $today): bool
    {
        $key = BirthdayKey::for($enrollmentId, $today->year);

        return StampEvent::query()->where('enrollment_id', $enrollmentId)->where('source', StampSource::Birthday)
            ->where(fn (Builder $bonuses) => $bonuses->where('idempotency_key', $key)->orWhere('created_at', '>=', Birthday::recentBonusesSince($today)))
            ->with('location:id,timezone')
            ->get(['id', 'idempotency_key', 'created_at', 'location_id'])
            ->contains(fn (StampEvent $bonus): bool => $bonus->idempotency_key === $key
                || Birthday::bonusTooRecent($bonus->created_at, $bonus->location->timezone, $today));
    }

    /** Whether the card got this year's bonus now: not when it had it already, or another lately, here or at another business. */
    private function give(CardEnrollment $enrollment, Location $site, Automation $automation, CarbonImmutable $today): bool
    {
        $key = BirthdayKey::for($enrollment->id, $today->year);

        try {
            return Outermost::transaction('A birthday bonus', function () use ($enrollment, $site, $automation, $today, $key): bool {
                $given = $this->context->bypass(function () use ($enrollment, $today): bool {
                    CardEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->first(['id']);

                    return $this->hadBonusLately($enrollment->id, $today);
                });

                return ! $given && ! $this->addStamps->handle($enrollment, StampRequest::system(StampSource::Birthday, $site, $key, $automation->stamps))->replayed;
            });
        } catch (StampRejected $refused) {
            // An account deleted meanwhile is expected; anything else (a key taken by another stamp) must show.
            if ($refused->rejection !== StampRejection::AccountDeleted) {
                Log::warning('A birthday bonus was refused.', ['enrollment_id' => $enrollment->id, 'reason' => $refused->rejection->value]);
            }

            return false;
        } catch (Throwable $failed) {
            Log::warning('A birthday bonus failed.', ['enrollment_id' => $enrollment->id, 'error' => $failed::class]);

            return false;
        }
    }
}
