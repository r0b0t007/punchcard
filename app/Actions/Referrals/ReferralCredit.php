<?php

declare(strict_types=1);

namespace App\Actions\Referrals;

use App\Enums\StampSource;
use App\Models\Business;
use App\Models\Location;
use App\Models\StampEvent;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where a referrer's stamps from friends go, and how many they had this
 * month (CHW-47): one answer for the stamp (GiveReferralStamp) and for
 * their card (DescribeReferral), so the count on the card is the count the
 * monthly limit is checked against.
 *
 * Read in bypass(): a customer's card spans an organization's businesses.
 */
final readonly class ReferralCredit
{
    public function __construct(private TenantContext $context) {}

    /**
     * The business an enrollment last proved it came to (a tap, a scan, a stamp by hand), among some businesses if
     * given.
     *
     * @param  Builder<Business>|null  $among
     */
    public function lastCameTo(int $enrollmentId, ?Builder $among = null): ?int
    {
        $business = $this->context->bypass(fn (): mixed => StampEvent::query()->where('enrollment_id', $enrollmentId)
            ->whereIn('source', StampSource::presenceValues())
            ->when($among, fn (Builder $events, Builder $among): Builder => $events->whereIn('business_id', (clone $among)->select('id')))
            ->latest('created_at')->latest('id')->value('business_id'));

        return is_numeric($business) ? (int) $business : null;
    }

    /**
     * Where the referrer's stamp is credited: the business they last proved they came to, so a franchise site never
     * carries a stamp, or a reward, of a customer who never came there; the given one (the friend's, or their link's)
     * for a referrer with no visit yet, or whose business has no open site any more. Its first open site.
     *
     * @return array{0: Business|null, 1: Location|null}
     */
    public function site(int $referrerEnrollmentId, int $otherwiseBusinessId): array
    {
        return $this->context->bypass(function () use ($referrerEnrollmentId, $otherwiseBusinessId): array {
            foreach (array_unique(array_filter([$this->lastCameTo($referrerEnrollmentId), $otherwiseBusinessId])) as $businessId) {
                $site = Location::query()->where('business_id', $businessId)->open()->orderBy('id')->first();

                if ($site instanceof Location) {
                    return [Business::query()->with('organization')->whereKey($businessId)->first(), $site];
                }
            }

            return [null, null];
        });
    }

    /** The referral stamps a referrer had this calendar month, in the time of the site they are credited at. */
    public function thisMonth(int $referrerEnrollmentId, Location $site, CarbonImmutable $now): int
    {
        $monthStart = $now->setTimezone(LocalMoment::timezoneOf($site))->startOfMonth()->utc();

        return $this->context->bypass(fn (): int => StampEvent::query()->where('enrollment_id', $referrerEnrollmentId)
            ->where('source', StampSource::Referral)->where('created_at', '>=', $monthStart)->count());
    }
}
