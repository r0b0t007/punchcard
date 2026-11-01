<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Actions\Stamps\RecentStamps;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\LocalMoment;
use Carbon\CarbonImmutable;

/**
 * The owner dashboard (CHW-33, B3 lite) for one business, or one of its open
 * locations: the last 30 local days (today included) against the 30 before.
 *
 * - A visit is a customer's presence stamps (StampSource::presenceValues:
 *   tap, QR, by hand) here on one local day. A customer is a person,
 *   whatever card they hold; system stamps and corrections are not visits.
 * - Days are the location's when one is picked, else the business's: the
 *   timezone of its first open location.
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
 * A fixed number of queries, however many stamps: the days are counted in
 * PHP from the ordered events against local midnights, with no date per row.
 * The window's presence stamps are plucked twice and read once more as a
 * subquery: the tenant guards allow neither toBase() nor a raw join, and
 * plucking skips loading thousands of models.
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

        $visits = $this->visitsByCustomer($business, $location?->id, $midnights);
        [$previous, $current] = [$this->window($visits, 0), $this->window($visits, self::DAYS)];
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
            'visitsPerDay' => array_map(fn (int $day): array => [
                'date' => $today->addDays($day - self::DAYS + 1)->format('Y-m-d'),
                'visits' => $current['perDay'][$day] ?? 0,
            ], range(0, self::DAYS - 1)),
            'recentStamps' => $this->recentStamps->handle($business, $location?->id, $now),
            'topCustomers' => $this->topCustomers($visits, $timezone),
            'locations' => array_values($locations->map(fn (Location $open): array => ['id' => $open->id, 'name' => $open->name])->all()),
            'location' => $location?->id,
        ];
    }

    /**
     * Each customer's visits over both windows: the latest presence stamp on
     * each local day, by day index.
     *
     * @param  list<string>  $midnights
     * @return array<int, array<int, string>> by user id, then day index
     */
    private function visitsByCustomer(Business $business, ?int $locationId, array $midnights): array
    {
        $presence = fn () => StampEvent::query()
            ->where('business_id', $business->id)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->whereIn('source', StampSource::presenceValues())
            ->where('created_at', '>=', $midnights[0])
            ->where('created_at', '<', $midnights[count($midnights) - 1]);
        // Plucked, not loaded as models (thousands of rows): the stored UTC time as text, under an alias so it is
        // not cast to a date, compared with the midnights; then each stamp's card.
        $stampedAt = $presence()->selectRaw('id, created_at as stamped_at')->orderBy('created_at')->orderBy('id')->pluck('stamped_at', 'id');
        $enrollmentOf = $presence()->pluck('enrollment_id', 'id');
        // The customers behind the cards: CardEnrollment's scope shows a franchisee exactly those who stamped here.
        $customers = CardEnrollment::query()->whereIn('id', $presence()->select('enrollment_id'))->pluck('user_id', 'id');

        $visits = [];
        $day = 0;

        foreach ($stampedAt as $id => $at) {
            $at = (string) $at;
            $userId = $customers->get($enrollmentOf->get($id));

            while ($at >= $midnights[$day + 1]) {
                $day++;
            }

            if ($userId !== null) {
                $visits[(int) $userId][$day] = $at;
            }
        }

        return $visits;
    }

    /**
     * Active customers, visits, the return rate and visits per day in the
     * window starting at day index $from.
     *
     * @param  array<int, array<int, string>>  $visits
     * @return array{active: int, visits: int, returnRate: int, perDay: array<int, int>}
     */
    private function window(array $visits, int $from): array
    {
        $active = 0;
        $returning = 0;
        $total = 0;
        $perDay = [];

        foreach ($visits as $days) {
            $inWindow = array_filter(array_keys($days), fn (int $day): bool => $day >= $from && $day < $from + self::DAYS);

            if ($inWindow === []) {
                continue;
            }

            $active++;
            $returning += count($inWindow) >= 2 ? 1 : 0;
            $total += count($inWindow);

            foreach ($inWindow as $day) {
                $perDay[$day - $from] = ($perDay[$day - $from] ?? 0) + 1;
            }
        }

        return [
            'active' => $active,
            'visits' => $total,
            'returnRate' => $active === 0 ? 0 : (int) round($returning / $active * 100),
            'perDay' => $perDay,
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
     * @param  array<int, array<int, string>>  $visits
     * @return list<array{id: int, name: string, visits: int, lastVisit: string}>
     */
    private function topCustomers(array $visits, string $timezone): array
    {
        $ranked = [];

        foreach ($visits as $userId => $days) {
            $inWindow = array_filter($days, fn (int $day): bool => $day >= self::DAYS, ARRAY_FILTER_USE_KEY);

            if ($inWindow !== []) {
                $ranked[] = ['id' => $userId, 'visits' => count($inWindow), 'last' => max($inWindow)];
            }
        }

        usort($ranked, fn (array $a, array $b): int => [$b['visits'], $b['last'], $a['id']] <=> [$a['visits'], $a['last'], $b['id']]);
        $ranked = array_slice($ranked, 0, self::LISTED);
        // First name and initial (CHW-44): how a business sees its customers.
        $names = User::query()->whereKey(array_column($ranked, 'id'))->get(['id', 'name'])
            ->mapWithKeys(fn (User $user): array => [$user->id => (string) $user->shortName()]);

        return array_map(fn (array $customer): array => [
            'id' => $customer['id'],
            'name' => (string) $names->get($customer['id'], ''),
            'visits' => $customer['visits'],
            'lastVisit' => CarbonImmutable::parse($customer['last'], 'UTC')->setTimezone($timezone)->format('Y-m-d'),
        ], $ranked);
    }

    /** The change in percent, or null when there was nothing to compare with. */
    private function change(int $current, int $previous): ?int
    {
        return $previous === 0 ? null : (int) round(($current - $previous) / $previous * 100);
    }
}
