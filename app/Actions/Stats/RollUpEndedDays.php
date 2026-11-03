<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Models\Business;
use App\Models\BusinessDailyStat;
use App\Models\Location;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rolls up each business's local days that have ended and are not rolled up
 * yet (CHW-45, RollUpDay), hourly from the schedule: each timezone gets its
 * yesterday within the hour after its midnight, and a signed-out tap's wait.
 * A day has ended once it has at every open site. A business that fails is
 * logged and left to the next run; the others go on. Never more than a week back, so a run stays short; a
 * longer gap, or the history before the first run, is punchcard:stats:rollup
 * --from's. The dashboard counts a day with no row live meanwhile.
 *
 * With $from, every ended day from then is rolled up again, whatever was
 * rolled up before. A business at a time, in bypass().
 */
final readonly class RollUpEndedDays
{
    private const int WEEK = 7;

    /** Beyond a pending tap's wait, for a stamp's transaction still open at midnight. */
    private const int SETTLE_MINUTES = 5;

    public function __construct(private RollUpDay $rollUpDay, private TenantContext $context) {}

    /** @return int how many business days were rolled up */
    public function handle(CarbonImmutable $now, ?int $businessId = null, ?string $from = null): int
    {
        return $this->context->bypass(function () use ($now, $businessId, $from): int {
            $count = 0;
            $businesses = Business::query()->when($businessId !== null, fn ($query) => $query->whereKey($businessId))->lazyById(100);

            foreach ($businesses as $business) {
                try {
                    foreach ($this->days($business, $now, $from) as $day) {
                        $this->rollUpDay->handle($business, $day);
                        $count++;
                    }
                } catch (Throwable $failed) {
                    // The others go on; the next run takes this one up where it stopped. Never the message.
                    Log::warning('The rollup skipped a business.', ['business_id' => $business->id, 'error' => $failed::class]);
                }
            }

            return $count;
        });
    }

    /**
     * The business's days to roll up, oldest first.
     *
     * @return list<string>
     */
    private function days(Business $business, CarbonImmutable $now, ?string $from): array
    {
        $sites = Location::query()->where('business_id', $business->id)->open()->orderBy('id')->get(['id', 'timezone']);
        // The business's time first (its first open site's, as RollUpDay counts it), then its other sites'.
        $timezones = $sites->isEmpty()
            ? collect([LocalMoment::timezoneOf(Location::query()->where('business_id', $business->id)->orderBy('id')->first(['id', 'timezone']))])
            : $sites->map(fn (Location $site): string => LocalMoment::timezoneOf($site));
        // A signed-out tap claimed later is stamped at the tap's time (ApplyTap): a day ends once its last one can be.
        $settled = $now->subMinutes((int) config('punchcard.taps.pending_minutes') + self::SETTLE_MINUTES);
        // Yesterday where the day began last: it has ended everywhere.
        $ended = (string) $timezones->map(fn (string $timezone): string => $settled->setTimezone($timezone)->subDay()->format('Y-m-d'))->min();
        $born = $business->created_at?->copy()->setTimezone($timezones->first())->format('Y-m-d') ?? $ended;
        $latest = BusinessDailyStat::query()->where('business_id', $business->id)->whereNull('location_id')->max('day');
        $start = $from ?? max(
            CarbonImmutable::parse($ended)->subDays(self::WEEK - 1)->format('Y-m-d'),
            $latest === null ? '' : CarbonImmutable::parse(substr((string) $latest, 0, 10))->addDay()->format('Y-m-d'),
        );

        $days = [];

        for ($day = CarbonImmutable::parse(max($start, $born)); $day->format('Y-m-d') <= $ended; $day = $day->addDay()) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }
}
