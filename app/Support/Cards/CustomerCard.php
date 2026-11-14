<?php

declare(strict_types=1);

namespace App\Support\Cards;

use App\Enums\CardMode;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Support\Branding\CardBrand;
use LogicException;

/**
 * A business's card as a customer screen shows it (the TapCard type in
 * resources/js/components/tap/tap-screen.tsx): the tap results (DescribeTap)
 * and the join page (CHW-31). Its colours are CardBrand's (the card's, else
 * the organization's; always the organization's in a franchise), and its
 * cover the card's banner, else the organization's cover (CHW-135, design
 * 24b). Pass the business with its organization loaded. The stamps
 * shown never pass the card's slots: a card made shorter (CHW-32) shows a
 * customer above the new count, or an older tap, as a full card.
 */
final class CustomerCard
{
    /**
     * @return array{businessName: string, locationName: ?string, cardName: string, stampsRequired: int, stampsCollected: int, rewardText: string, brandColor: ?string, brandForeground: ?string, stampStyle: ?string, coverUrl: ?string}
     */
    public static function of(Business $business, LoyaltyCard $card, ?Location $location = null, int $stamps = 0): array
    {
        return self::shape($business->name, $location?->name, $card, $business->organization, $stamps);
    }

    /**
     * The stamps a customer's copy of the card shows: a progressive card's
     * lifetime stamps, a cyclic card's since its last reward (CHW-27).
     */
    public static function stampsOf(LoyaltyCard $card, CardEnrollment $enrollment): int
    {
        return $card->mode === CardMode::Progressive ? $enrollment->lifetime_stamps : $enrollment->current_stamps;
    }

    /**
     * The stamps still to collect for the card's next reward (My cards'
     * "closest to a reward", CHW-217): a cyclic card's to its reward, a
     * progressive card's to its next tier, null once every tier is earned
     * (or its tiers can't be read: nothing to show then, not a broken page).
     */
    public static function stampsToReward(LoyaltyCard $card, CardEnrollment $enrollment): ?int
    {
        if ($card->mode !== CardMode::Progressive) {
            return max(0, $card->stamps_required - $enrollment->current_stamps);
        }

        try {
            $tiers = ProgressiveTiers::parse($card->tiers);
        } catch (LogicException) {
            return null;
        }

        foreach ($tiers as $tier) {
            if ($tier['stamps'] > $enrollment->lifetime_stamps) {
                return $tier['stamps'] - $enrollment->lifetime_stamps;
            }
        }

        return null;
    }

    /**
     * The card as a whole program shows it, under its organization's name
     * (My cards, CHW-27): a franchise's card is held at every franchisee.
     *
     * @return array{businessName: string, locationName: ?string, cardName: string, stampsRequired: int, stampsCollected: int, rewardText: string, brandColor: ?string, brandForeground: ?string, stampStyle: ?string, coverUrl: ?string}
     */
    public static function ofProgram(Organization $organization, LoyaltyCard $card, int $stamps = 0): array
    {
        return self::shape($organization->name, null, $card, $organization, $stamps);
    }

    /**
     * @return array{businessName: string, locationName: ?string, cardName: string, stampsRequired: int, stampsCollected: int, rewardText: string, brandColor: ?string, brandForeground: ?string, stampStyle: ?string, coverUrl: ?string}
     */
    private static function shape(string $name, ?string $locationName, LoyaltyCard $card, Organization $organization, int $stamps): array
    {
        return [
            ...CardBrand::of($card, $organization),
            'businessName' => $name,
            'locationName' => $locationName,
            'cardName' => $card->name,
            'stampsRequired' => $card->stamps_required,
            'stampsCollected' => max(0, min($stamps, $card->stamps_required)),
            'rewardText' => $card->reward_text,
            'stampStyle' => $card->stamp_style,
            'coverUrl' => $card->bannerUrl() ?? $organization->coverUrl(),
        ];
    }
}
