<?php

declare(strict_types=1);

namespace App\Actions\Stats;

use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\BusinessDailyStat;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One local day of a business rolled up from the ledger (CHW-45,
 * BusinessDailyStat): a row for each open site, and one for the whole
 * business. Read from the ledger, never from the visit days, so a queue
 * running late never skews it. Days with nothing are written too: the
 * latest row tells how far the rollup reached. Rewritten whole each time,
 * so a retry, or a later run over the same day, leaves it right.
 *
 * - A stamp falls on its site's local day, as the visit days count it
 *   (SummariseCustomer): the whole business's visits are its sites' (every
 *   one, archived too), a customer at two sites counted once.
 * - Rewards redeemed fall on the business's day (its first open site's
 *   time), as the dashboard counts them, and each row keeps that timezone:
 *   a row from before the business's time changed is not read as today's.
 *
 * In bypass(), every read kept to the business: in a franchise, a visit to
 * A2 never counts at A1.
 */
final readonly class RollUpDay
{
    public function __construct(private TenantContext $context) {}

    /** @param  string  $day  the local day, Y-m-d */
    public function handle(Business $business, string $day): void
    {
        $this->context->bypass(function () use ($business, $day): void {
            $sites = Location::query()->where('business_id', $business->id)->orderBy('id')->get(['id', 'timezone', 'archived_at']);
            $open = $sites->whereNull('archived_at');
            // With every site archived, the business keeps its days: its first site's.
            $timezone = LocalMoment::timezoneOf($open->first() ?? $sites->first());
            $windows = $sites->mapWithKeys(fn (Location $site): array => [$site->id => $this->window(LocalMoment::timezoneOf($site), $day)])->all();
            $rows = [$this->row($business, null, $timezone, $day, $windows)];

            foreach ($open as $site) {
                $rows[] = $this->row($business, $site->id, LocalMoment::timezoneOf($site), $day, [$site->id => $windows[$site->id]]);
            }

            DB::transaction(function () use ($business, $day, $rows): void {
                BusinessDailyStat::query()->where('business_id', $business->id)->where('day', $day)->delete();
                BusinessDailyStat::query()->insert($rows);
            });
        });
    }

    /**
     * The day's figures at one site, or at the whole business.
     *
     * @param  array<int, array{0: string, 1: string}>  $windows  each site counted, with its day's start and end (UTC)
     * @return array<string, mixed>
     */
    private function row(Business $business, ?int $locationId, string $timezone, string $day, array $windows): array
    {
        [$start, $end] = $this->window($timezone, $day);
        // The stamps at the sites counted, each during (or, with $before, before) its site's day.
        $stamps = fn (bool $before = false): Builder => StampEvent::query()
            ->where('business_id', $business->id)
            ->where(function (Builder $query) use ($windows, $before): void {
                foreach ($windows as $siteId => [$from, $until]) {
                    $query->orWhere(fn (Builder $site) => $site->where('location_id', $siteId)
                        ->when($before, fn (Builder $at) => $at->where('created_at', '<', $from), fn (Builder $at) => $at->where('created_at', '>=', $from)->where('created_at', '<', $until)));
                }
            });
        // Whose enrollments a presence stamp was made on.
        $came = fn (Builder $stamps): Builder => $stamps->select('enrollment_id')->whereIn('source', StampSource::presenceValues());
        $visitors = fn (): Builder => CardEnrollment::query()->whereIn('id', $came($stamps()));

        return [
            'organization_id' => $business->organization_id,
            'business_id' => $business->id,
            'location_id' => $locationId,
            'day' => $day,
            'timezone' => $timezone,
            // A person, whatever card they hold.
            'visits' => $visitors()->distinct()->count('user_id'),
            'new_customers' => $visitors()
                ->whereNotIn('user_id', CardEnrollment::query()->select('user_id')->whereIn('id', $came($stamps(before: true))))
                ->distinct()->count('user_id'),
            'stamps' => (int) $stamps()->where('source', '!=', StampSource::Correction->value)->sum('qty'),
            'rewards_unlocked' => Reward::query()->whereIn('stamp_event_id', $stamps()->select('id'))->count(),
            'rewards_redeemed' => Reward::query()
                ->where('status', RewardStatus::Redeemed)
                ->where('redeemed_business_id', $business->id)
                ->when($locationId !== null, fn (Builder $query) => $query->where('redeemed_location_id', $locationId))
                ->where('redeemed_at', '>=', $start)
                ->where('redeemed_at', '<', $end)
                ->count(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array{0: string, 1: string} the local day's start and end, as stored UTC times */
    private function window(string $timezone, string $day): array
    {
        $from = CarbonImmutable::parse($day, $timezone)->startOfDay();

        return [$from->utc()->format('Y-m-d H:i:s'), $from->addDay()->utc()->format('Y-m-d H:i:s')];
    }
}
