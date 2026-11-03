<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Enums\CustomerSegment;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\User;
use App\Support\LocalMoment;
use Carbon\CarbonImmutable;

/**
 * Who is going quiet at a business now (CHW-45, B3, design 16): the
 * Customers page's Going quiet tab (CustomerSegment::Quiet), how many, and
 * the LISTED with the most visits, then the latest visit. Each by first
 * name and initial, their usual gap, and the days since their last visit in
 * the business's time, as the Customers page counts them.
 *
 * A customer of the business, not of one location: the dashboard sends it
 * apart from its figures, so the location filter never asks for it again.
 * Read in the owner's tenant.
 */
final readonly class DescribeGoingQuiet
{
    private const int LISTED = 5;

    /**
     * @return array{count: int, customers: list<array{id: int, name: string, visits: int, usualGapDays: ?int, lastVisitDays: int}>}
     */
    public function handle(Business $business, CarbonImmutable $now): array
    {
        $quiet = fn () => BusinessCustomer::query()->where('business_id', $business->id)->inSegment(CustomerSegment::Quiet, $now);
        $listed = $quiet()->orderByDesc('visits')->orderByDesc('last_visit_at')->orderByDesc('id')->limit(self::LISTED)->get();
        $names = User::query()->whereKey($listed->pluck('user_id')->all())->get(['id', 'name'])
            ->mapWithKeys(fn (User $user): array => [$user->id => (string) $user->shortName()]);
        $timezone = LocalMoment::timezoneOfBusiness($business->id);

        return [
            'count' => $quiet()->count(),
            'customers' => array_values($listed->map(fn (BusinessCustomer $customer): array => [
                'id' => $customer->id,
                'name' => (string) $names->get($customer->user_id, ''),
                'visits' => $customer->visits,
                'usualGapDays' => $customer->usual_gap_days,
                'lastVisitDays' => LocalMoment::daysSince($customer->last_visit_at, $timezone, $now),
            ])->all()),
        ];
    }
}
