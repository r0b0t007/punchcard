<?php

declare(strict_types=1);

namespace App\Actions\Automations;

use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRejected;
use App\Actions\Stamps\StampRequest;
use App\Enums\StampSource;
use App\Models\Automation;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The birthday bonus (CHW-46): the automation's stamps (1 to 3) on each card
 * of the organization this business honours, for its customers (a visit
 * here, BusinessCustomer) whose birthday is today in its time
 * (User::birthdayOn: 29 February on 28 February of a year without one).
 * Never an anonymised account. No follow or phone needed: the stamps are
 * the treat, the message is RunAutomations'.
 *
 * Once a year per card, across the organization: each is a system stamp
 * keyed "birthday:{card}:{year}", the year of that birthday, and a card
 * with that key at any of its businesses is left out. Checked under the
 * card's lock, which AddStamps takes too, so two runs never both give it;
 * in a franchise the first business to reach it gives it. Credited to the
 * business's first open site, in the business's tenant (as a tap is, so
 * what follows, a reward unlocked and pushed, is queued there). One refused
 * (an account deleted meanwhile) is left out; one failing otherwise is
 * logged by id, and the others go on.
 *
 * Called by RunAutomations, only while the business sends
 * (AutomationWindow::sendsNow).
 */
final readonly class GiveBirthdayStamps
{
    public function __construct(private AddStamps $addStamps, private TenantContext $context) {}

    /** @return int the cards stamped */
    public function handle(Business $business, Automation $automation, CarbonImmutable $now): int
    {
        // Its sites are read in bypass(): outside a tenant, the business's time would be the app's.
        $today = $this->context->bypass(fn (): CarbonImmutable => $now->setTimezone(LocalMoment::timezoneOfBusiness($business->id)));
        [$site, $enrollments] = $this->context->bypass(fn (): array => [
            Location::query()->where('business_id', $business->id)->open()->orderBy('id')->first(),
            CardEnrollment::query()
                ->where('organization_id', $business->organization_id)
                ->whereIn('user_id', User::query()->select('id')->notAnonymised()->birthdayOn($today))
                ->whereIn('user_id', BusinessCustomer::query()->select('user_id')->where('business_id', $business->id))
                ->whereHas('card', fn (Builder $cards) => $cards->where('active', true)->whereHas('businesses', fn (Builder $businesses) => $businesses->whereKey($business->id)))
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
                $stamped += $this->give($enrollment, $site, $automation, 'birthday:'.$enrollment->id.':'.$today->year) ? 1 : 0;
            }
        } finally {
            $this->context->restore($previous);
        }

        return $stamped;
    }

    /** Whether the card got this year's bonus now: not when it had it already, here or at another business. */
    private function give(CardEnrollment $enrollment, Location $site, Automation $automation, string $key): bool
    {
        try {
            return DB::transaction(function () use ($enrollment, $site, $automation, $key): bool {
                $given = $this->context->bypass(function () use ($enrollment, $key): bool {
                    CardEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->first(['id']);

                    return StampEvent::query()->where('enrollment_id', $enrollment->id)->where('source', StampSource::Birthday)
                        ->where('idempotency_key', $key)->exists();
                });

                return ! $given && ! $this->addStamps->handle($enrollment, StampRequest::system(StampSource::Birthday, $site, $key, $automation->stamps))->replayed;
            });
        } catch (StampRejected) {
            return false;
        } catch (Throwable $failed) {
            Log::warning('A birthday bonus failed.', ['enrollment_id' => $enrollment->id, 'error' => $failed::class]);

            return false;
        }
    }
}
