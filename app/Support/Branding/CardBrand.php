<?php

declare(strict_types=1);

namespace App\Support\Branding;

use App\Enums\OrganizationType;
use App\Models\LoyaltyCard;
use App\Models\Organization;

/**
 * The colours a card paints with on every screen (ADR 0007, CHW-135): its own
 * colour and the text colour stored for it (CHW-32), else its organization's
 * brand and the text worked out for that (Organization::brandForeground). A
 * franchise's cards always show the organization's brand, whatever colour a
 * card kept from before. Pass the organization with its type and brand_color.
 */
final class CardBrand
{
    /**
     * @return array{brandColor: ?string, brandForeground: ?string}
     */
    public static function of(?LoyaltyCard $card, ?Organization $organization): array
    {
        $own = $organization?->type === OrganizationType::Franchise ? null : $card?->brandColor();

        if ($own !== null) {
            return ['brandColor' => $own, 'brandForeground' => $card->brandForeground()];
        }

        return ['brandColor' => $organization?->brand_color, 'brandForeground' => $organization?->brandForeground()];
    }
}
