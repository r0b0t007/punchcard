<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Actions\Stamps\RecentStamps;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CustomerVisitDay;
use App\Models\Location;
use App\Models\Reward;
use App\Models\User;
use App\Support\LocalMoment;
use Carbon\CarbonImmutable;

/**
 * The owner dashboard (CHW-33, B3 lite) for one business, or one of its open
 * locations: the last 30 local days (today included) against the 30 before.
 *
 * - A visit is a customer's presence stamps (StampSource::presenceValues:
 *   tap, QR, by hand) here on one local day: a day they came in
 *   (CustomerVisitDay, CHW-45), in the time of the site they came to. A
 *   customer is a person, whatever card they hold, and two sites on one day
 *   make one visit; system stamps and corrections are not visits.
 * - The windows are the location's days when one is picked, else the
 *   business's: the timezone of its first open location.
 * - Active customers visited at least once; the return rate is the share of
 *   them who visited on 2 or more days; rewards redeemed counts those
 *   redeemed here.
 * - Each figure has its change against the 30 days before: in percent
 *   (null when there was nothing before), in points for the return rate.
 * - The latest stamps here (every source, so corrections show) and the top
 *   10 customers by visits, then by their latest visit.
 *
 * Only this business's events count, so a franchisee never sees its
 * sibling's customers (ADR 0006): rewards are filtered on where they were
 * redeemed, as an org admin's reward scope spans the organization.
 *
 * A fixed number of queries, however many stamps: visits are read from the
 * customers' visit days, one row per customer and one per day, never from
 * the stamps themselves. Those are written on the queue after each stamp
 * (SummariseCustomers), so a visit counts here once the queue has run it,
 * while the latest stamps read the ledger at once; a job that failed every
 * try is in failed_jobs, and punchcard:customers:summarise writes it again.
 *
 * A visit's day is the local day of the site it was made at; the windows
 * are the business's days. Sites in two timezones (multi-location, CHW-53)
 * can put a late visit at one on the business's next day.
 */
final readonly class DescribeDashboard
{
    private const int DAYS = 30;

    private const int LISTED = 10;

    public function __construct(private RecentStamps $recentStamps) {}

    /**
     * @param  int|null  $locationId  one of the business's open locations; any other id means all of them
     * @return array{
     *     kpis: array{activeCustomers: array{value: int, change: ?int}, visits: array{value: int, change: ?int}, returnRate: array{value: int, change: ?int}, rewardsRedeemed: array{value: int, change: ?int}},
     *     visitsPerDay: list<array{date: string, visits: int}>,
     *     recentStamps: list<array{id: int, customer: ?string, card: ?string, location: ?string, source: string, sourceLabel: string, qty: int, at: array{day: 'today'|'other', date: string, time: string}, secondsAgo: int}>,
     *     topCustomers: list<array{id: int, name: string, visits: int, lastVisit: string}>,
     *     locations: list<array{id: int, name: string}>,
     *     location: ?int,
     * }
     */
    public function handle(Business $business, ?int $locationId, CarbonImmutable $now): array
    {
        $locations = Location::query()->where('business_id', $business->id)->open()->orderBy('id')->get(['id', 'name', 'timezone']);
        $location = $locations->firstWhere('id', $locationId);
        // With every location archived, the business keeps its days: its first location's, archived or not.
        $timezone = LocalMoment::timezoneOf($location ?? $locations->first()
            ?? Location::query()->where('business_id', $business->id)->orderBy('id')->first(['id', 'timezone']));
        $today = $now->setTimezone($timezone)->startOfDay();
        // Local midnights as stored UTC times: day $k runs from $midnights[$k] to $midnights[$k + 1]; days 0 to 29 are the previous window.
        $midnights = array_map(fn (int $day): string => $today->addDays($day)->utc()->format('Y-m-d H:i:s'), range(1 - 2 * self::DAYS, 1));
        // The same days as dates, today last.
        $days = array_map(fn (int $day): string => $today->addDays($day)->format('Y-m-d'), range(1 - 2 * self::DAYS, 0));

        [$customers, $perDay] = $this->visits($business, $location?->id, $days);
        [$previous, $current] = [$this->window($customers, 'previous'), $this->window($customers, 'current')];
        $rewards = $this->rewardsRedeemed($business, $location?->id, $midnights);

        return [
            'kpis' => [
                'activeCustomers' => ['value' => $current['active'], 'change' => $this->change($current['active'], $previous['active'])],
                'visits' => ['value' => $current['visits'], 'change' => $this->change($current['visits'], $previous['visits'])],
                'returnRate' => [
                    'value' => $current['returnRate'],
                    'change' => $previous['active'] === 0 ? null : $current['returnRate'] - $previous['returnRate'],
                ],
                'rewardsRedeemed' => ['value' => $rewards['current'], 'change' => $this->change($rewards['current'], $rewards['previous'])],
            ],
            'visitsPerDay' => array_map(fn (string $day): array => ['date' => $day, 'visits' => $perDay[$day] ?? 0], array_slice($days, self::DAYS)),
            'recentStamps' => $this->recentStamps->handle($business, $location?->id, $now),
            'topCustomers' => $this->topCustomers($customers),
            'locations' => array_values($locations->map(fn (Location $open): array => ['id' => $open->id, 'name' => $open->name])->all()),
            'location' => $location?->id,
        ];
    }

    /**
     * Each customer's visit days in both windows, and the customers who came
     * in on each day of the current one, from their visit days here
     * (CHW-45, CustomerVisitDay): one row per customer, then one per day.
     *
     * @param  list<string>  $days  the local days of both windows, oldest first
     * @return array{0: array<int, array{previous: int, current: int, last: ?string, lastAt: ?string}>, 1: array<string, int>}
     */
    private function visits(Business $business, ?int $locationId, array $days): array
    {
        $from = $days[self::DAYS];
        $here = fn () => CustomerVisitDay::query()
            ->where('business_id', $business->id)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->where('visited_on', '>=', $days[0])
            ->where('visited_on', '<=', $days[count($days) - 1]);

        // A customer at two sites on one day made one visit to the business: days are counted distinct.
        $customers = $here()
            ->groupBy('user_id')
            ->selectRaw('user_id, count(distinct case when visited_on < ? then visited_on end) as previous_days, count(distinct case when visited_on >= ? then visited_on end) as current_days, max(case when visited_on >= ? then visited_on end) as last_day, max(case when visited_on >= ? then last_at end) as latest_at', [$from, $from, $from, $from])
            ->get()
            ->mapWithKeys(fn (CustomerVisitDay $row): array => [(int) $row->user_id => [
                'previous' => (int) $row->getAttribute('previous_days'),
                'current' => (int) $row->getAttribute('current_days'),
                'last' => is_string($row->getAttribute('last_day')) ? substr($row->getAttribute('last_day'), 0, 10) : null,
                'lastAt' => is_string($row->getAttribute('latest_at')) ? $row->getAttribute('latest_at') : null,
            ]])
            ->all();
        $perDay = $here()
            ->where('visited_on', '>=', $from)
            ->groupBy('visited_on')
            ->selectRaw('visited_on as day, count(distinct user_id) as customers')
            ->get()
            ->mapWithKeys(fn (CustomerVisitDay $row): array => [substr((string) $row->getAttribute('day'), 0, 10) => (int) $row->getAttribute('customers')])
            ->all();

        return [$customers, $perDay];
    }

    /**
     * Active customers, visits and the return rate in one window.
     *
     * @param  array<int, array{previous: int, current: int, last: ?string, lastAt: ?string}>  $customers
     * @param  'previous'|'current'  $window
     * @return array{active: int, visits: int, returnRate: int}
     */
    private function window(array $customers, string $window): array
    {
        $days = array_filter(array_column($customers, $window));
        $active = count($days);

        return [
            'active' => $active,
            'visits' => array_sum($days),
            'returnRate' => $active === 0 ? 0 : (int) round(count(array_filter($days, fn (int $visits): bool => $visits >= 2)) / $active * 100),
        ];
    }

    /**
     * Rewards redeemed here in each window, whoever's they are.
     *
     * @param  list<string>  $midnights
     * @return array{previous: int, current: int}
     */
    private function rewardsRedeemed(Business $business, ?int $locationId, array $midnights): array
    {
        $redeemed = fn (string $from, string $until): int => Reward::query()
            ->where('status', RewardStatus::Redeemed)
            ->where('redeemed_business_id', $business->id)
            ->when($locationId !== null, fn ($query) => $query->where('redeemed_location_id', $locationId))
            ->where('redeemed_at', '>=', $from)
            ->where('redeemed_at', '<', $until)
            ->count();

        return [
            'previous' => $redeemed($midnights[0], $midnights[self::DAYS]),
            'current' => $redeemed($midnights[self::DAYS], $midnights[count($midnights) - 1]),
        ];
    }

    /**
     * The 10 customers with the most visits in the window, then the latest.
     *
     * @param  array<int, array{previous: int, current: int, last: ?string, lastAt: ?string}>  $customers
     * @return list<array{id: int, name: string, visits: int, lastVisit: string}>
     */
    private function topCustomers(array $customers): array
    {
        $ranked = array_filter($customers, fn (array $customer): bool => $customer['current'] > 0);
        uksort($ranked, fn (int $a, int $b): int => [$ranked[$b]['current'], $ranked[$b]['lastAt'], $a] <=> [$ranked[$a]['current'], $ranked[$a]['lastAt'], $b]);
        $ranked = array_slice($ranked, 0, self::LISTED, preserve_keys: true);
        // First name and initial (CHW-44): how a business sees its customers.
        $names = User::query()->whereKey(array_keys($ranked))->get(['id', 'name'])
            ->mapWithKeys(fn (User $user): array => [$user->id => (string) $user->shortName()]);

        return array_map(fn (int $id): array => [
            'id' => $id,
            'name' => (string) $names->get($id, ''),
            'visits' => $ranked[$id]['current'],
            'lastVisit' => (string) $ranked[$id]['last'],
        ], array_keys($ranked));
    }

    /** The change in percent, or null when there was nothing to compare with. */
    private function change(int $current, int $previous): ?int
    {
        return $previous === 0 ? null : (int) round(($current - $previous) / $previous * 100);
    }
}
