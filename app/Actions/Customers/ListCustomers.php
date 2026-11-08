<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\CustomerSegment;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Support\Customers\CustomerList;
use App\Support\LocalMoment;
use Carbon\CarbonImmutable;

/**
 * A business's customers list (CHW-44, B4, design 15a), as its owner sees it (CustomerList): the tabs and their
 * counts, one tab's customers a page at a time, by first name, with the last visit counted in the business's time.
 *
 * Read in the owner's tenant: the business's own customers only.
 */
final readonly class ListCustomers
{
    public const int PER_PAGE = CustomerList::PER_PAGE;

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
        $list = CustomerList::of(fn () => BusinessCustomer::query()->where('business_id', $business->id), $segment, $search, $page,
            LocalMoment::timezoneOfBusiness($business->id), $now);

        // Who they are stays on the server: the page knows each customer by this business's reference alone.
        unset($list['userIds']);

        return $list;
    }
}
