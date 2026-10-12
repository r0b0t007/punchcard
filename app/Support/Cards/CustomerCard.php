<?php

declare(strict_types=1);

namespace App\Support\Cards;

use App\Enums\CardMode;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\Organization;

/**
 * A business's card as a customer screen shows it (the TapCard type in
 * resources/js/components/tap/tap-screen.tsx): the tap results (DescribeTap)
 * and the join page (CHW-31). The brand colour is the card's, else the
 * organization's (ADR 0007, CHW-32), with the text colour the card stores
 * for its own. Pass the business with its organization loaded. The stamps
 * shown never pass the card's slots: a card made shorter (CHW-32) shows a
 * customer above the new count, or an older tap, as a full card.
 */
final class CustomerCard
{
    /**
     * @return array{businessName: string, locationName: ?string, cardName: string, stampsRequired: int, stampsCollected: int, rewardText: string, brandColor: ?string, brandForeground: ?string, stampStyle: ?string}
     */
    public static function of(Business $business, LoyaltyCard $card, ?Location $location = null, int $stamps = 0): array
    {
        // The organization is read only for a card without its own colour.
        return self::shape($business->name, $location?->name, $card, $card->brandColor() ?? $business->organization->brand_color, $stamps);
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
     * The card as a whole program shows it, under its organization's name
     * (My cards, CHW-27): a franchise's card is held at every franchisee.
     *
     * @return array{businessName: string, locationName: ?string, cardName: string, stampsRequired: int, stampsCollected: int, rewardText: string, brandColor: ?string, brandForeground: ?string, stampStyle: ?string}
     */
    public static function ofProgram(Organization $organization, LoyaltyCard $card, int $stamps = 0): array
    {
        return self::shape($organization->name, null, $card, $card->brandColor() ?? $organization->brand_color, $stamps);
    }

    /**
     * @return array{businessName: string, locationName: ?string, cardName: string, stampsRequired: int, stampsCollected: int, rewardText: string, brandColor: ?string, brandForeground: ?string, stampStyle: ?string}
     */
    private static function shape(string $name, ?string $locationName, LoyaltyCard $card, ?string $brandColor, int $stamps): array
    {
        return [
            'businessName' => $name,
            'locationName' => $locationName,
            'cardName' => $card->name,
            'stampsRequired' => $card->stamps_required,
            'stampsCollected' => max(0, min($stamps, $card->stamps_required)),
            'rewardText' => $card->reward_text,
            'brandColor' => $brandColor,
            'brandForeground' => $card->brandForeground(),
            'stampStyle' => $card->stamp_style,
        ];
    }
}
