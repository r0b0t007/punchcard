<?php

declare(strict_types=1);

namespace App\Support\Cards;

use App\Models\Business;
use App\Models\Location;
use App\Models\LoyaltyCard;

/**
 * A business's card as a customer screen shows it (the TapCard type in
 * resources/js/components/tap/tap-screen.tsx): the tap results (DescribeTap)
 * and the join page (CHW-31). The brand colour is the organization's
 * (ADR 0007). Pass the business with its organization loaded.
 */
final class CustomerCard
{
    /**
     * @return array{businessName: string, locationName: ?string, cardName: string, stampsRequired: int, stampsCollected: int, rewardText: string, brandColor: ?string, stampStyle: ?string}
     */
    public static function of(Business $business, LoyaltyCard $card, ?Location $location = null, int $stamps = 0): array
    {
        return [
            'businessName' => $business->name,
            'locationName' => $location?->name,
            'cardName' => $card->name,
            'stampsRequired' => $card->stamps_required,
            'stampsCollected' => $stamps,
            'rewardText' => $card->reward_text,
            'brandColor' => $business->organization->brand_color,
            'stampStyle' => $card->stamp_style,
        ];
    }
}
