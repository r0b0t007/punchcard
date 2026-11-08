<?php

declare(strict_types=1);

namespace App\Support\Customers;

use App\Enums\CustomerSegment;
use App\Models\BusinessCustomer;
use App\Models\OrganizationCustomer;
use App\Models\User;
use App\Support\LocalMoment;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * A customers list as B4 shows it (CHW-44, design 15a), at a business or across a franchise's sites (CHW-365, design
 * 23): how many are in each tab, then one tab's customers, PER_PAGE a page, newest visit first (the New tab: newest
 * first visit), optionally only those whose first name starts with what was typed. Each by first name and initial,
 * with their visits, how many days since the last one (in $timezone), the rewards they redeemed and their status.
 */
final class CustomerList
{
    public const int PER_PAGE = 25;

    /**
     * @template TCustomer of BusinessCustomer|OrganizationCustomer
     *
     * @param  Closure(): Builder<TCustomer>  $customers  the customers listed, a new query each call
     * @return array{
     *     segment: value-of<CustomerSegment>,
     *     q: string|null,
     *     counts: array<value-of<CustomerSegment>, int>,
     *     customers: list<array{id: int, name: string, visits: int, lastVisitDays: int, rewardsRedeemed: int, status: string}>,
     *     userIds: list<int>,
     *     page: array{current: int, last: int, from: int|null, to: int|null, total: int},
     * }
     */
    public static function of(Closure $customers, CustomerSegment $segment, ?string $search, int $page, string $timezone, CarbonImmutable $now): array
    {
        $counts = [];

        foreach (CustomerSegment::cases() as $each) {
            $counts[$each->value] = $customers()->inSegment($each, $now)->count();
        }

        $tab = fn () => $customers()
            ->inSegment($segment, $now)
            ->when($search !== null, fn (Builder $query) => self::named($query, (string) $search))
            ->orderByDesc($segment === CustomerSegment::New ? 'first_visit_at' : 'last_visit_at')
            ->orderByDesc('id');
        $listed = $tab()->paginate(self::PER_PAGE, page: $page);

        // Past the last page (an old link, customers moved on): the last page.
        if ($listed->total() > 0 && $listed->currentPage() > $listed->lastPage()) {
            $listed = $tab()->paginate(self::PER_PAGE, page: $listed->lastPage());
        }
        $names = User::query()->whereKey($listed->getCollection()->pluck('user_id')->all())->get(['id', 'name'])
            ->mapWithKeys(fn (User $user): array => [$user->id => (string) $user->shortName()]);

        return [
            'segment' => $segment->value,
            'q' => $search,
            'counts' => $counts,
            'customers' => array_values($listed->getCollection()->map(fn (BusinessCustomer|OrganizationCustomer $customer): array => [
                'id' => $customer->id,
                'name' => (string) $names->get($customer->user_id, ''),
                'visits' => $customer->visits,
                'lastVisitDays' => LocalMoment::daysSince($customer->last_visit_at, $timezone, $now),
                'rewardsRedeemed' => $customer->rewards_redeemed,
                'status' => $customer->status($now)->value,
            ])->all()),
            // Who each row is, in the same order: for the caller, never the page.
            'userIds' => array_values($listed->getCollection()->map(fn (BusinessCustomer|OrganizationCustomer $customer): int => $customer->user_id)->all()),
            'page' => [
                'current' => $listed->currentPage(),
                'last' => $listed->lastPage(),
                'from' => $listed->firstItem(),
                'to' => $listed->lastItem(),
                'total' => $listed->total(),
            ],
        ];
    }

    /**
     * Who is called that: a first name starts the name. %, _ and \ are ignored, never patterns, so what only they make
     * up matches no one. Looked up on the listed customers only, never the platform's users.
     *
     * @param  Builder<BusinessCustomer|OrganizationCustomer>  $query
     */
    private static function named(Builder $query, string $search): void
    {
        $letters = str_replace(['%', '_', '\\'], '', $search);

        $letters === ''
            ? $query->whereKey([])
            : $query->whereHas('user', fn (Builder $user) => $user->whereLike('name', $letters.'%'));
    }
}
