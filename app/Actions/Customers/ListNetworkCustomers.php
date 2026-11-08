<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\CustomerSegment;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\Organization;
use App\Models\OrganizationCustomer;
use App\Support\Customers\CustomerList;
use App\Support\LocalMoment;
use Carbon\CarbonImmutable;

/**
 * A franchise's customers across its sites (CHW-365, B4 at network level, design 23), as its head office sees them:
 * each person once (OrganizationCustomer), with the same tabs, counts, search and pages as a business's list
 * (CustomerList), and the businesses they are a customer of. The last visit is counted in the organization's time.
 *
 * Read in the org admin's tenant, at network level: the organization's own customers only.
 */
final readonly class ListNetworkCustomers
{
    /**
     * @return array{
     *     segment: value-of<CustomerSegment>,
     *     q: string|null,
     *     counts: array<value-of<CustomerSegment>, int>,
     *     customers: list<array{id: int, name: string, visits: int, lastVisitDays: int, rewardsRedeemed: int, status: string, sites: list<string>}>,
     *     page: array{current: int, last: int, from: int|null, to: int|null, total: int},
     * }
     */
    public function handle(Organization $organization, CustomerSegment $segment, ?string $search, int $page, CarbonImmutable $now): array
    {
        $list = CustomerList::of(fn () => OrganizationCustomer::query()->where('organization_id', $organization->id), $segment, $search, $page,
            LocalMoment::timezoneOfOrganization($organization->id), $now);
        $sites = self::sitesOf($organization->id, $list['userIds']);
        $list['customers'] = array_map(fn (array $row, int $userId): array => [...$row, 'sites' => $sites[$userId] ?? []], $list['customers'], $list['userIds']);
        // Who they are stays on the server: the page knows each customer by the network's reference alone.
        unset($list['userIds']);

        return $list;
    }

    /**
     * The businesses each of these people is a customer of, by name, oldest business first (the list, and one
     * customer's page, DescribeNetworkCustomer).
     *
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    public static function sitesOf(int $organizationId, array $userIds): array
    {
        $rows = BusinessCustomer::query()->where('organization_id', $organizationId)->whereIn('user_id', $userIds)
            ->orderBy('business_id')->get(['user_id', 'business_id']);
        $names = Business::query()->whereKey($rows->pluck('business_id')->unique()->values()->all())->pluck('name', 'id');
        $sites = [];

        foreach ($rows as $row) {
            $sites[$row->user_id][] = (string) $names->get($row->business_id, '');
        }

        return $sites;
    }
}
