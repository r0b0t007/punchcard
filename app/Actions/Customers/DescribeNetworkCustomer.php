<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\CustomerNote;
use App\Models\Organization;
use App\Models\OrganizationCustomer;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Support\Customers\CustomerHistory;
use App\Support\LocalMoment;
use Carbon\CarbonImmutable;

/**
 * One customer as a franchise's head office sees them (CHW-365, B5 at network level, design 23), read only: their
 * status, visits, usual gap, last visit and rewards redeemed across its sites (OrganizationCustomer), the businesses
 * they are a customer of, their progress on each of its cards, what happened at every one of its sites, newest first,
 * each event naming its business ($shown of it), and every business's notes on them, each naming its business.
 * Nothing to change: stamps and notes are each business's (no sites to stamp at, no note form).
 *
 * Read in the org admin's tenant, at network level: the organization's own customers, stamps and notes only.
 */
final readonly class DescribeNetworkCustomer
{
    /**
     * @return array{
     *     customer: array{id: int, name: string, status: string, visits: int, usualGapDays: int|null, lastVisitDays: int, rewardsRedeemed: int, friendsCameIn: int, sites: list<string>},
     *     cards: list<array{id: int, name: string, rewardText: string, stamps: int, toReward: int|null}>,
     *     history: list<array{key: string, kind: string, source: string|null, qty: int, card: string|null, location: string|null, business: string|null, by: string|null, reason: string|null, byScan: bool, at: array{day: string, date: string, time: string}}>,
     *     shown: int,
     *     step: int,
     *     more: bool,
     *     locations: list<array{id: int, name: string}>,
     *     correctionLocations: list<array{id: int, name: string}>,
     *     notes: list<array{id: int, body: string, by: string|null, business: string|null, at: array{day: string, date: string, time: string}}>,
     * }
     */
    public function handle(Organization $organization, OrganizationCustomer $customer, int $shown, CarbonImmutable $now): array
    {
        $timezone = LocalMoment::timezoneOfOrganization($organization->id);
        $theirs = CardEnrollment::query()->where('organization_id', $organization->id)->where('user_id', $customer->user_id)
            ->with('card')->orderBy('id')->get()->keyBy('id');
        $enrollmentIds = $theirs->keys()->all();
        $businesses = Business::query()->where('organization_id', $organization->id)->pluck('name', 'id')
            ->map(fn (mixed $name): string => (string) $name)->all();
        $shownHistory = CustomerHistory::of(
            StampEvent::query()->where('organization_id', $organization->id)->whereIn('enrollment_id', $enrollmentIds),
            // Their cards are the organization's, so wherever one was redeemed is one of its businesses.
            Reward::query()->whereIn('enrollment_id', $enrollmentIds)->whereNotNull('redeemed_business_id'),
            $theirs, $shown, $now, $businesses,
        );
        $notes = CustomerNote::query()->where('organization_id', $organization->id)->where('user_id', $customer->user_id)
            ->with('author:id,name,anonymised_at')->latest('id')->get();

        return [
            'customer' => [
                'id' => $customer->id,
                'name' => (string) $customer->user?->shortName(),
                'status' => $customer->status($now)->value,
                'visits' => $customer->visits,
                'usualGapDays' => $customer->usual_gap_days,
                'lastVisitDays' => LocalMoment::daysSince($customer->last_visit_at, $timezone, $now),
                'rewardsRedeemed' => $customer->rewards_redeemed,
                'friendsCameIn' => $this->friendsCameIn($organization, $enrollmentIds),
                'sites' => ListNetworkCustomers::sitesOf($organization->id, [$customer->user_id])[$customer->user_id] ?? [],
            ],
            'cards' => CustomerHistory::cards($theirs->values()),
            'history' => $shownHistory['history'],
            'shown' => $shown,
            'step' => DescribeCustomer::SHOWN,
            // Up to MOST, as at a business: the page asks no further.
            'more' => $shown < DescribeCustomer::MOST && $shownHistory['more'],
            // Read only: no site to add stamps at or take them back from.
            'locations' => [],
            'correctionLocations' => [],
            'notes' => array_values($notes->map(fn (CustomerNote $note): array => [
                'id' => $note->id,
                'body' => $note->body,
                'by' => CustomerHistory::staff($note->author),
                'business' => $businesses[$note->business_id] ?? null,
                'at' => LocalMoment::of($note->created_at, $timezone, $now),
            ])->all()),
        ];
    }

    /**
     * The friends a customer invited who came in to any of the organization's sites (CHW-47): cards referred by
     * theirs, with a stamp proving a visit there. A count only.
     *
     * @param  array<int, int|string>  $enrollmentIds
     */
    private function friendsCameIn(Organization $organization, array $enrollmentIds): int
    {
        return CustomerHistory::friendsCameIn(StampEvent::query()->where('organization_id', $organization->id), $organization->id, $enrollmentIds);
    }
}
