<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\CustomerSegment;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\User;
use App\Support\LocalMoment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A business's customers list (CHW-44, B4, design 15a), as its owner sees
 * it: how many are in each tab, then one tab's customers, PER_PAGE a page,
 * newest visit first (the New tab: newest first visit), optionally only
 * those whose first name starts with what was typed. Each by first name and
 * initial, with their visits, how many days since the last one (in the
 * business's time), the rewards they redeemed there and their status.
 *
 * Read in the owner's tenant: the business's own customers only.
 */
final readonly class ListCustomers
{
    public const int PER_PAGE = 25;

    /**
     * Who is called that: a first name starts the name. % and _ are letters
     * there, not patterns, so what only they make up matches no one.
     *
     * @return Builder<User>|list<int>
     */
    private function named(string $search): Builder|array
    {
        $letters = str_replace(['%', '_', '\\'], '', $search);

        return $letters === '' ? [] : User::query()->select('id')->whereLike('name', $letters.'%');
    }

    /**
     * @return array{
     *     segment: value-of<CustomerSegment>,
     *     q: string|null,
     *     counts: array<value-of<CustomerSegment>, int>,
     *     customers: list<array{id: int, name: string, visits: int, lastVisitDays: int, rewardsRedeemed: int, status: string}>,
     *     page: array{current: int, last: int, from: int|null, to: int|null, total: int},
     * }
     */
    public function handle(Business $business, CustomerSegment $segment, ?string $search, int $page, CarbonImmutable $now): array
    {
        $ofBusiness = fn () => BusinessCustomer::query()->where('business_id', $business->id);
        $counts = [];

        foreach (CustomerSegment::cases() as $each) {
            $counts[$each->value] = $ofBusiness()->inSegment($each, $now)->count();
        }

        $customers = $ofBusiness()
            ->inSegment($segment, $now)
            ->when($search !== null, fn ($query) => $query->whereIn('user_id', $this->named((string) $search)))
            ->orderByDesc($segment === CustomerSegment::New ? 'first_visit_at' : 'last_visit_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, page: $page);
        $names = User::query()->whereKey($customers->getCollection()->pluck('user_id')->all())->get(['id', 'name'])
            ->mapWithKeys(fn (User $user): array => [$user->id => (string) $user->shortName()]);
        $timezone = LocalMoment::timezoneOf($business->firstLocation()->first());
        // Calendar days between the dates, in the business's time: a clock change's 23 or 25 hours are still a day.
        $daysSince = fn (CarbonImmutable $at): int => (int) CarbonImmutable::parse($at->setTimezone($timezone)->format('Y-m-d'))
            ->diffInDays(CarbonImmutable::parse($now->setTimezone($timezone)->format('Y-m-d')));

        return [
            'segment' => $segment->value,
            'q' => $search,
            'counts' => $counts,
            'customers' => array_values($customers->getCollection()->map(fn (BusinessCustomer $customer): array => [
                'id' => $customer->id,
                'name' => (string) $names->get($customer->user_id, ''),
                'visits' => $customer->visits,
                'lastVisitDays' => $daysSince($customer->last_visit_at->toImmutable()),
                'rewardsRedeemed' => $customer->rewards_redeemed,
                'status' => $customer->status($now)->value,
            ])->all()),
            'page' => [
                'current' => $customers->currentPage(),
                'last' => $customers->lastPage(),
                'from' => $customers->firstItem(),
                'to' => $customers->lastItem(),
                'total' => $customers->total(),
            ],
        ];
    }
}
