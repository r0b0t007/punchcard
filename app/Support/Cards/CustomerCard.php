<?php

declare(strict_types=1);

namespace App\Support\Cards;

use App\Models\Business;
use App\Models\Location;
use App\Models\LoyaltyCard;

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
        return [
            'businessName' => $business->name,
            'locationName' => $location?->name,
            'cardName' => $card->name,
            'stampsRequired' => $card->stamps_required,
            'stampsCollected' => max(0, min($stamps, $card->stamps_required)),
            'rewardText' => $card->reward_text,
            'brandColor' => $card->brandColor() ?? $business->organization->brand_color,
            'brandForeground' => $card->brandForeground(),
            'stampStyle' => $card->stamp_style,
        ];
    }
}
