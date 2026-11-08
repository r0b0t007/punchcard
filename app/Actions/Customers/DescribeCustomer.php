<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Actions\Counter\ScanLocations;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;
use App\Models\CustomerNote;
use App\Models\Location;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Support\Customers\CustomerHistory;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * One customer as their business's owner sees them (CHW-44, B5, design
 * 15c): first name and initial and their status; their visits, usual gap,
 * last visit and rewards redeemed here (BusinessCustomer); their progress on
 * each of the organization's cards the business sees (shared across a
 * franchise); and what happened at this business, newest first, $shown of
 * it: stamps (by tap, scan or hand, with who and why, and corrections) and
 * rewards redeemed here. For adding stamps or taking them back: the sites
 * they can be given at (added: the open ones; taken back: any). And the
 * team's notes on them, newest first, every one, so the team can read and
 * delete all that the customer's data export holds.
 *
 * Read in the owner's tenant: a franchisee's history is its own; a sister
 * franchisee's stamps count on the card, never in this history.
 */
final readonly class DescribeCustomer
{
    public const int SHOWN = 20;

    public const int MOST = 500;

    public function __construct(private TenantContext $context, private ScanLocations $scanLocations) {}

    /**
     * @return array{
     *     customer: array{id: int, name: string, status: string, visits: int, usualGapDays: int|null, lastVisitDays: int, rewardsRedeemed: int, friendsCameIn: int},
     *     cards: list<array{id: int, name: string, rewardText: string, stamps: int, toReward: int|null}>,
     *     history: list<array{key: string, kind: string, source: string|null, qty: int, card: string|null, location: string|null, business: string|null, by: string|null, reason: string|null, byScan: bool, at: array{day: string, date: string, time: string}}>,
     *     shown: int,
     *     step: int,
     *     more: bool,
     *     locations: list<array{id: int, name: string}>,
     *     correctionLocations: list<array{id: int, name: string}>,
     *     notes: list<array{id: int, body: string, by: string|null, at: array{day: string, date: string, time: string}}>,
     * }
     */
    public function handle(Business $business, BusinessCustomer $customer, int $shown, CarbonImmutable $now): array
    {
        $timezone = LocalMoment::timezoneOfBusiness($business->id);
        // Every card of theirs in the organization, as the summary counts them: a card stamped only at a sister
        // franchisee can still be redeemed here. The stamps and redemptions read below are this business's only.
        $theirs = $this->context->bypass(fn () => CardEnrollment::query()->where('organization_id', $business->organization_id)
            ->where('user_id', $customer->user_id)->with('card:id,name')->get()->keyBy('id'));
        $enrollmentIds = $theirs->keys()->all();
        $shownHistory = CustomerHistory::of(
            StampEvent::query()->where('business_id', $business->id)->whereIn('enrollment_id', $enrollmentIds),
            Reward::query()->where('redeemed_business_id', $business->id)->whereIn('enrollment_id', $enrollmentIds),
            $theirs, $shown, $now,
        );
        // The cards the business sees (shared across a franchise), with their progress.
        $cards = CardEnrollment::query()->where('user_id', $customer->user_id)->with('card')->orderBy('id')->get();
        $notes = CustomerNote::query()->where('business_id', $business->id)->where('user_id', $customer->user_id)
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
                'friendsCameIn' => $this->friendsCameIn($business, $enrollmentIds),
            ],
            'cards' => CustomerHistory::cards($cards),
            'history' => $shownHistory['history'],
            'shown' => $shown,
            'step' => self::SHOWN,
            // Up to MOST: the page asks no further.
            'more' => $shown < self::MOST && $shownHistory['more'],
            'locations' => $this->scanLocations->handle($business, $this->context->locationId()),
            'correctionLocations' => array_values(Location::query()->where('business_id', $business->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Location $location): array => ['id' => $location->id, 'name' => $location->name])->all()),
            'notes' => array_values($notes->map(fn (CustomerNote $note): array => [
                'id' => $note->id,
                'body' => $note->body,
                'by' => CustomerHistory::staff($note->author),
                'at' => LocalMoment::of($note->created_at, $timezone, $now),
            ])->all()),
        ];
    }

    /**
     * The friends a customer invited who came in to this business (CHW-47): cards referred by theirs, with a stamp
     * proving a visit here. A count only, and of this business's own customers: a friend who only went to a sister
     * franchisee, or never came, is not this business's to see.
     *
     * @param  array<int, int|string>  $enrollmentIds
     */
    private function friendsCameIn(Business $business, array $enrollmentIds): int
    {
        return $this->context->bypass(fn (): int => StampEvent::query()->where('business_id', $business->id)
            ->whereIn('source', StampSource::presenceValues())
            ->whereIn('enrollment_id', CardEnrollment::query()->where('organization_id', $business->organization_id)->whereIn('referred_by', $enrollmentIds)->select('id'))
            ->distinct()->count('enrollment_id'));
    }
}
