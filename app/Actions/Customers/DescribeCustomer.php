<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\StampSource;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Cards\CustomerCard;
use App\Support\LocalMoment;
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

    /**
     * @return array{
     *     customer: array{id: int, name: string, status: string, visits: int, usualGapDays: int|null, lastVisitDays: int, rewardsRedeemed: int},
     *     cards: list<array{id: int, name: string, rewardText: string, stamps: int, toReward: int|null}>,
     *     history: list<array{key: string, kind: string, source: string|null, qty: int, card: string|null, location: string|null, by: string|null, reason: string|null, byScan: bool, at: array{day: string, date: string, time: string}}>,
     *     shown: int,
     *     more: bool,
     * }
     */
    public function handle(Business $business, BusinessCustomer $customer, int $shown, CarbonImmutable $now): array
    {
        $timezone = LocalMoment::timezoneOf($business->firstLocation()->first());
        $theirs = CardEnrollment::query()->where('user_id', $customer->user_id)->with('card')->orderBy('id')->get();
        $enrollmentIds = $theirs->pluck('id')->all();

        $stamps = StampEvent::query()->where('business_id', $business->id)->whereIn('enrollment_id', $enrollmentIds)
            ->with(['location:id,name,timezone', 'staff:id,name'])
            ->latest('created_at')->latest('id')->limit($shown + 1)->get()
            ->map(fn (StampEvent $stamp): array => ['sort' => [$stamp->created_at->getTimestamp(), 0, $stamp->id], 'event' => [
                'key' => 'stamp-'.$stamp->id,
                'kind' => 'stamp',
                'source' => $stamp->source->value,
                'qty' => $stamp->qty,
                'card' => $theirs->firstWhere('id', $stamp->enrollment_id)?->card?->name,
                'location' => $stamp->location?->name,
                'by' => in_array($stamp->source, [StampSource::Qr, StampSource::Manual, StampSource::Correction], true) ? $stamp->staff?->shortName() : null,
                'reason' => $stamp->reason,
                'byScan' => false,
                'at' => LocalMoment::of($stamp->created_at, LocalMoment::timezoneOf($stamp->location), $now),
            ]]);
        $redeemed = Reward::query()->where('redeemed_business_id', $business->id)->whereIn('enrollment_id', $enrollmentIds)
            ->with(['redeemedLocation:id,name,timezone', 'redeemedBy:id,name'])
            ->latest('redeemed_at')->latest('id')->limit($shown + 1)->get()
            ->map(fn (Reward $reward): array => ['sort' => [(int) $reward->redeemed_at?->getTimestamp(), 1, $reward->id], 'event' => [
                'key' => 'redeemed-'.$reward->id,
                'kind' => 'redeemed',
                'source' => null,
                'qty' => 0,
                'card' => $reward->reward_text,
                'location' => $reward->redeemedLocation?->name,
                'by' => $reward->redeemedBy?->shortName(),
                'reason' => null,
                'byScan' => $reward->redeemed_by_scan,
                'at' => LocalMoment::of($reward->redeemed_at ?? $now, LocalMoment::timezoneOf($reward->redeemedLocation), $now),
            ]]);
        // Newest first; in the same second, the redemption above the stamp it followed (a scan stamps, then redeems).
        $history = $stamps->concat($redeemed)->sortByDesc('sort')->values();

        return [
            'customer' => [
                'id' => $customer->id,
                'name' => (string) User::query()->whereKey($customer->user_id)->first()?->shortName(),
                'status' => $customer->status($now)->value,
                'visits' => $customer->visits,
                'usualGapDays' => $customer->usual_gap_days,
                'lastVisitDays' => LocalMoment::daysSince($customer->last_visit_at, $timezone, $now),
                'rewardsRedeemed' => $customer->rewards_redeemed,
            ],
            'cards' => array_values($theirs->filter(fn (CardEnrollment $enrollment): bool => $enrollment->card !== null)
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
            'more' => $history->count() > $shown,
        ];
    }
}
