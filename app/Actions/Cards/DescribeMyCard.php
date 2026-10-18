<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Enums\CardMode;
use App\Enums\RewardStatus;
use App\Models\Business;
use App\Models\CardBusiness;
use App\Models\CardEnrollment;
use App\Models\Follow;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Cards\CustomerCard;
use App\Support\Cards\ProgressiveTiers;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * One of the customer's cards in full (CHW-27, C6): the card as its program
 * shows it (CustomerCard::ofProgram), its banner, a progressive card's tiers
 * with those reached, the rewards ready on it, its terms and what the café
 * tells its customers (hours, phone, links: http(s) only), the latest 20
 * stamps on it (every source, corrections too) with where they were, the
 * open places of the operating businesses honouring it with a Maps link,
 * and whether the customer follows the program for offers (Follow), or
 * can: a closed program takes no new follow, and consent needs a verified
 * email.
 *
 * Pass a card the customer holds (MyCardRequest). In bypass(): a customer
 * has no tenant. A fixed number of queries, however long the history.
 */
final readonly class DescribeMyCard
{
    private const int HISTORY = 20;

    public function __construct(private TenantContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(CardEnrollment $enrollment, User $viewer, CarbonImmutable $now): array
    {
        return $this->context->bypass(function () use ($enrollment, $viewer, $now): array {
            $card = LoyaltyCard::query()->findOrFail($enrollment->card_id);
            $organization = Organization::query()->whereKey($enrollment->organization_id)->firstOrFail(['id', 'name', 'brand_color', 'logo_path', 'archived_at']);

            return [
                'id' => $enrollment->id,
                'card' => CustomerCard::ofProgram($organization, $card, CustomerCard::stampsOf($card, $enrollment)),
                'logoUrl' => $organization->logoUrl(),
                'bannerUrl' => $card->bannerUrl(),
                'paused' => ! $card->active,
                'tiers' => $this->tiers($card, $enrollment),

                'rewardsReady' => Reward::query()->where('enrollment_id', $enrollment->id)->where('status', RewardStatus::Available)->count(),
                'terms' => $card->terms,
                'info' => [
                    'hours' => $card->info['hours'] ?? null,
                    'phone' => $card->info['phone'] ?? null,
                    // Only web links reach a customer's screen.
                    'links' => array_values(array_filter(
                        $card->info['links'] ?? [],
                        fn (array $link): bool => preg_match('#^https?://#i', $link['url']) === 1,
                    )),
                ],
                'history' => $this->history($enrollment, $now),
                'venues' => $this->venues($card),
                // A closed program takes no new follow (SetFollow).
                'canFollow' => $organization->takesNewFollows(),
                // Consent comes from whoever owns the inbox (SetFollow).
                'mustVerify' => ! $viewer->hasVerifiedEmail(),
                'following' => Follow::query()
                    ->where('user_id', $enrollment->user_id)
                    ->where('organization_id', $enrollment->organization_id)
                    ->where('notify', true)
                    ->exists(),
            ];
        });
    }

    /**
     * A progressive card's tiers, lowest first, those reached marked; none
     * for a cyclic card, nor for tiers stored malformed (an old or raw row:
     * every save checks them), which the card still shows without.
     *
     * @return list<array{stamps: int, reward: string, reached: bool}>|null
     */
    private function tiers(LoyaltyCard $card, CardEnrollment $enrollment): ?array
    {
        if ($card->mode !== CardMode::Progressive) {
            return null;
        }

        try {
            $tiers = ProgressiveTiers::parse($card->tiers);
        } catch (LogicException) {
            return null;
        }

        return array_map(fn (array $tier): array => [
            'stamps' => $tier['stamps'],
            'reward' => $tier['reward'],
            'reached' => $enrollment->lifetime_stamps >= $tier['stamps'],
        ], $tiers);
    }

    /**
     * @return list<array{id: int, sourceLabel: string, qty: int, place: string, at: array{day: 'today'|'other', date: string, time: string}}>
     */
    private function history(CardEnrollment $enrollment, CarbonImmutable $now): array
    {
        $events = StampEvent::query()
            ->where('enrollment_id', $enrollment->id)
            ->latest('created_at')
            ->latest('id')
            ->limit(self::HISTORY)
            ->get(['id', 'business_id', 'location_id', 'source', 'qty', 'created_at']);
        $businesses = Business::query()->whereKey($events->pluck('business_id')->unique()->all())->pluck('name', 'id');
        $locations = Location::query()->whereKey($events->pluck('location_id')->unique()->all())->get(['id', 'name', 'timezone'])->keyBy('id');

        return array_values($events->map(function (StampEvent $event) use ($businesses, $locations, $now): array {
            $location = $locations->get($event->location_id);

            return [
                'id' => $event->id,
                'sourceLabel' => $event->source->getLabel(),
                'qty' => $event->qty,
                'place' => implode(' · ', array_filter([$businesses->get($event->business_id), $location?->name])),
                'at' => LocalMoment::of($event->created_at, LocalMoment::timezoneOf($location), $now),
            ];
        })->all());
    }

    /**
     * The open places of the operating businesses honouring the card, by
     * business then place, each with a Maps search: its coordinates, else
     * its address.
     *
     * @return list<array{id: int, businessName: string, name: string, address: ?string, mapsUrl: ?string}>
     */
    private function venues(LoyaltyCard $card): array
    {
        $businesses = Business::query()
            ->operating()
            ->whereIn('id', CardBusiness::query()->where('card_id', $card->id)->select('business_id'))
            ->pluck('name', 'id');
        $locations = Location::query()
            ->whereIn('business_id', $businesses->keys()->all())
            ->open()
            ->get(['id', 'business_id', 'name', 'address', 'lat', 'lng'])
            ->sortBy(fn (Location $location): string => $businesses->get($location->business_id)."\u{0}".$location->name);

        return array_values($locations->map(function (Location $location) use ($businesses): array {
            $query = $location->lat !== null && $location->lng !== null ? "{$location->lat},{$location->lng}" : $location->address;

            return [
                'id' => $location->id,
                'businessName' => (string) $businesses->get($location->business_id),
                'name' => $location->name,
                'address' => $location->address,
                'mapsUrl' => $query === null || $query === '' ? null : 'https://www.google.com/maps/search/?'.http_build_query(['api' => 1, 'query' => $query]),
            ];
        })->all());
    }
}
