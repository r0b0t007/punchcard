<?php

declare(strict_types=1);

namespace App\Actions\Fraud;

use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\StampEvent;
use App\Notifications\DailyCapStreakMail;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells a business's owners that a customer reached a card's daily limit
 * there DAYS days in a row (CHW-36): a regular, or stamps collected for
 * others. A day counts when the stamps that prove presence at the business
 * (nfc, qr, manual) reached the cap, in the site's own day, as AddStamps
 * counts it; another business of the card never counts. One email per
 * streak, on its DAYS-th day (the day before it was not full), so a daily
 * regular on a low limit is told once, not every day; a failed send is
 * owed again when the job retries. Read in bypass(): a queued job acts for
 * no user, and the owners are told what their own business saw.
 */
final readonly class AlertCapStreak
{
    public const int DAYS = 3;

    public function __construct(private TenantContext $context) {}

    /** @param  string  $date  the site's local day the cap was reached (Y-m-d) */
    public function handle(int $enrollmentId, int $businessId, int $locationId, string $date): void
    {
        $this->context->bypass(function () use ($enrollmentId, $businessId, $locationId, $date): void {
            $enrollment = CardEnrollment::query()->with(['card', 'user'])->whereKey($enrollmentId)->first();
            // A suspended or closed business hears nothing.
            $business = Business::query()->operating()->whereKey($businessId)->first();
            $cap = $enrollment?->card?->daily_cap;

            if (! $enrollment instanceof CardEnrollment || ! $business instanceof Business || $cap === null) {
                return;
            }

            $timezone = LocalMoment::timezoneOf(Location::query()->whereKey($locationId)->first());
            $day = fn (int $back): CarbonImmutable => CarbonImmutable::parse($date.' 12:00', $timezone)->subDays($back);
            $full = fn (CarbonImmutable $day): bool => (int) StampEvent::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('business_id', $business->id)
                ->whereIn('source', StampSource::presenceValues())
                ->where('created_at', '>=', LocalMoment::dayStart($timezone, $day))
                ->where('created_at', '<', LocalMoment::dayEnd($timezone, $day))
                ->sum('qty') >= $cap;
            $days = array_map($day, range(self::DAYS - 1, 0));

            if (in_array(false, array_map($full, $days), true) || $full($day(self::DAYS))) {
                return;
            }

            $key = "fraud-alert:cap:{$enrollment->id}:{$business->id}:{$date}";

            if (! Cache::add($key, true, now()->addDays(2))) {
                return;
            }

            try {
                Notification::send($business->owners()->get(), new DailyCapStreakMail(
                    customer: $enrollment->user?->firstName(),
                    card: $enrollment->card->name ?? '',
                    business: $business->name,
                    days: array_map(fn (CarbonImmutable $day): string => $day->toDateString(), $days),
                ));
            } catch (Throwable $failed) {
                Cache::forget($key);

                throw $failed;
            }
        });
    }
}
