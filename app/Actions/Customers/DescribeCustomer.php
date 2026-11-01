<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Cards\CustomerCard;
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
 * rewards redeemed here.
 *
 * Read in the owner's tenant: a franchisee's history is its own; a sister
 * franchisee's stamps count on the card, never in this history.
 */
final readonly class DescribeCustomer
{
    public const int SHOWN = 20;

    public const int MOST = 500;

    public function __construct(private TenantContext $context) {}

    /**
     * @return array{
     *     customer: array{id: int, name: string, status: string, visits: int, usualGapDays: int|null, lastVisitDays: int, rewardsRedeemed: int},
     *     cards: list<array{id: int, name: string, rewardText: string, stamps: int, toReward: int|null}>,
     *     history: list<array{key: string, kind: string, source: string|null, qty: int, card: string|null, location: string|null, by: string|null, reason: string|null, byScan: bool, at: array{day: string, date: string, time: string}}>,
     *     shown: int,
     *     step: int,
     *     more: bool,
     * }
     */
    public function handle(Business $business, BusinessCustomer $customer, int $shown, CarbonImmutable $now): array
    {
        $timezone = LocalMoment::timezoneOf($business->firstLocation()->first());
        // Every card of theirs in the organization, as the summary counts them: a card stamped only at a sister
        // franchisee can still be redeemed here. The stamps and redemptions read below are this business's only.
        $theirs = $this->context->bypass(fn () => CardEnrollment::query()->where('organization_id', $business->organization_id)
            ->where('user_id', $customer->user_id)->with('card:id,name')->get()->keyBy('id'));
        $enrollmentIds = $theirs->keys()->all();
        // Who on the staff, by first name and initial; no one for an account since deleted.
        $staff = fn (?User $user): ?string => ! $user instanceof User || $user->isAnonymised() ? null : $user->shortName();

        $stamps = StampEvent::query()->where('business_id', $business->id)->whereIn('enrollment_id', $enrollmentIds)
            ->with(['location:id,name,timezone', 'staff:id,name,anonymised_at'])
            ->latest('created_at')->latest('id')->limit($shown + 1)->get()
            ->map(fn (StampEvent $stamp): array => ['sort' => [$stamp->created_at->getTimestamp(), 0, $stamp->id], 'event' => [
                'key' => 'stamp-'.$stamp->id,
                'kind' => 'stamp',
                'source' => $stamp->source->value,
                'qty' => $stamp->qty,
                'card' => $theirs->get($stamp->enrollment_id)?->card?->name,
                'location' => $stamp->location?->name,
                // Whoever gave it: scanned, by hand, taken back, or armed the stamper for a tap.
                'by' => $staff($stamp->staff),
                'reason' => $stamp->reason,
                'byScan' => false,
                'at' => LocalMoment::of($stamp->created_at, LocalMoment::timezoneOf($stamp->location), $now),
            ]]);
        $redeemed = Reward::query()->where('redeemed_business_id', $business->id)->whereIn('enrollment_id', $enrollmentIds)
            ->with(['redeemedLocation:id,name,timezone', 'redeemedBy:id,name,anonymised_at'])
            ->latest('redeemed_at')->latest('id')->limit($shown + 1)->get()
            ->map(function (Reward $reward) use ($staff, $now): array {
                $at = $reward->redeemed_at ?? $now;

                return ['sort' => [$at->getTimestamp(), 1, $reward->id], 'event' => [
                    'key' => 'redeemed-'.$reward->id,
                    'kind' => 'redeemed',
                    'source' => null,
                    'qty' => 0,
                    'card' => $reward->reward_text,
                    'location' => $reward->redeemedLocation?->name,
                    'by' => $staff($reward->redeemedBy),
                    'reason' => null,
                    'byScan' => $reward->redeemed_by_scan,
                    'at' => LocalMoment::of($at, LocalMoment::timezoneOf($reward->redeemedLocation), $now),
                ]];
            });
        // Newest first; in the same second, the redemption above the stamp it followed (a scan stamps, then redeems).
        $history = $stamps->concat($redeemed)->sortByDesc('sort')->values();
        // The cards the business sees (shared across a franchise), with their progress.
        $cards = CardEnrollment::query()->where('user_id', $customer->user_id)->with('card')->orderBy('id')->get();

        return [
            'customer' => [
                'id' => $customer->id,
                'name' => (string) $customer->user?->shortName(),
                'status' => $customer->status($now)->value,
                'visits' => $customer->visits,
                'usualGapDays' => $customer->usual_gap_days,
                'lastVisitDays' => LocalMoment::daysSince($customer->last_visit_at, $timezone, $now),
                'rewardsRedeemed' => $customer->rewards_redeemed,
            ],
            'cards' => array_values($cards->filter(fn (CardEnrollment $enrollment): bool => $enrollment->card !== null)
                ->map(function (CardEnrollment $enrollment): array {
                    $card = $enrollment->card;

                    return [
                        'id' => $enrollment->id,
                        'name' => $card->name,
                        'rewardText' => $card->reward_text,
                        'stamps' => CustomerCard::stampsOf($card, $enrollment),
                        'toReward' => CustomerCard::stampsToReward($card, $enrollment),
                    ];
                })->all()),
            'history' => array_values($history->take($shown)->map(fn (array $item): array => $item['event'])->all()),
            'shown' => $shown,
            'step' => self::SHOWN,
            // Up to MOST: the page asks no further.
            'more' => $shown < self::MOST && $history->count() > $shown,
        ];
    }
}
