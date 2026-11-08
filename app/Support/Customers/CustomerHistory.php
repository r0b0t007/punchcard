<?php

declare(strict_types=1);

namespace App\Support\Customers;

use App\Enums\StampSource;
use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Cards\CustomerCard;
use App\Support\LocalMoment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What B5 shows of a customer (CHW-44, design 15c), at a business or across a franchise's sites (CHW-365, design 23):
 * what happened, newest first, $shown of it (stamps, by tap, scan or hand, with who and why, and corrections; rewards
 * redeemed), and their progress on each card. The caller picks which stamps and redemptions; across sites each event
 * also names its business.
 */
final class CustomerHistory
{
    /**
     * @param  Builder<StampEvent>  $stamps  the stamps to show
     * @param  Builder<Reward>  $redeemed  the redemptions to show
     * @param  Collection<int|string, CardEnrollment>  $theirs  their enrollments by id, with their card
     * @param  array<int, string>|null  $businesses  business names by id, to name each event's (across sites); null at one business
     * @return array{history: list<array{key: string, kind: string, source: string|null, qty: int, card: string|null, location: string|null, business: string|null, by: string|null, reason: string|null, byScan: bool, at: array{day: string, date: string, time: string}}>, more: bool}
     */
    public static function of(Builder $stamps, Builder $redeemed, Collection $theirs, int $shown, CarbonImmutable $now, ?array $businesses = null): array
    {
        $business = fn (?int $id): ?string => $businesses === null || $id === null ? null : ($businesses[$id] ?? null);

        $stamped = $stamps->with(['location:id,name,timezone', 'staff:id,name,anonymised_at'])
            ->latest('created_at')->latest('id')->limit($shown + 1)->get()
            ->map(fn (StampEvent $stamp): array => ['sort' => [$stamp->created_at->getTimestamp(), 0, $stamp->id], 'event' => [
                'key' => 'stamp-'.$stamp->id,
                'kind' => 'stamp',
                'source' => $stamp->source->value,
                'qty' => $stamp->qty,
                'card' => $theirs->get($stamp->enrollment_id)?->card?->name,
                'location' => $stamp->location?->name,
                'business' => $business($stamp->business_id),
                // Whoever gave it: scanned, by hand, taken back, or armed the stamper for a tap.
                'by' => self::staff($stamp->staff),
                'reason' => $stamp->reason,
                'byScan' => false,
                'at' => LocalMoment::of($stamp->created_at, LocalMoment::timezoneOf($stamp->location), $now),
            ]]);
        $rewards = $redeemed->with(['redeemedLocation:id,name,timezone', 'redeemedBy:id,name,anonymised_at'])
            ->latest('redeemed_at')->latest('id')->limit($shown + 1)->get()
            ->map(function (Reward $reward) use ($business, $now): array {
                $at = $reward->redeemed_at ?? $now;

                return ['sort' => [$at->getTimestamp(), 1, $reward->id], 'event' => [
                    'key' => 'redeemed-'.$reward->id,
                    'kind' => 'redeemed',
                    'source' => null,
                    'qty' => 0,
                    'card' => $reward->reward_text,
                    'location' => $reward->redeemedLocation?->name,
                    'business' => $business($reward->redeemed_business_id),
                    'by' => self::staff($reward->redeemedBy),
                    'reason' => null,
                    'byScan' => $reward->redeemed_by_scan,
                    'at' => LocalMoment::of($at, LocalMoment::timezoneOf($reward->redeemedLocation), $now),
                ]];
            });
        // Newest first; in the same second, the redemption above the stamp it followed (a scan stamps, then redeems).
        $history = $stamped->concat($rewards)->sortByDesc('sort')->values();

        return [
            'history' => array_values($history->take($shown)->map(fn (array $item): array => $item['event'])->all()),
            'more' => $history->count() > $shown,
        ];
    }

    /**
     * Their progress on each card.
     *
     * @param  Collection<int, CardEnrollment>  $enrollments  with their card
     * @return list<array{id: int, name: string, rewardText: string, stamps: int, toReward: int|null}>
     */
    public static function cards(Collection $enrollments): array
    {
        return array_values($enrollments->filter(fn (CardEnrollment $enrollment): bool => $enrollment->card !== null)
            ->map(function (CardEnrollment $enrollment): array {
                $card = $enrollment->card;

                return [
                    'id' => $enrollment->id,
                    'name' => $card->name,
                    'rewardText' => $card->reward_text,
                    'stamps' => CustomerCard::stampsOf($card, $enrollment),
                    'toReward' => CustomerCard::stampsToReward($card, $enrollment),
                ];
            })->all());
    }

    /**
     * The friends a customer invited who came in (CHW-47): the organization's cards referred by theirs, with a stamp
     * among $stamps proving a visit. A count only. The caller picks where a visit counts: one business, or every site.
     *
     * @param  Builder<StampEvent>  $stamps
     * @param  array<int, int|string>  $enrollmentIds
     */
    public static function friendsCameIn(Builder $stamps, int $organizationId, array $enrollmentIds): int
    {
        return $stamps->whereIn('source', StampSource::presenceValues())
            ->whereIn('enrollment_id', CardEnrollment::query()->where('organization_id', $organizationId)->whereIn('referred_by', $enrollmentIds)->select('id'))
            ->distinct()->count('enrollment_id');
    }

    /** Who on the staff, by first name and initial; no one for an account since deleted. */
    public static function staff(?User $user): ?string
    {
        return ! $user instanceof User || $user->isAnonymised() ? null : $user->shortName();
    }
}
