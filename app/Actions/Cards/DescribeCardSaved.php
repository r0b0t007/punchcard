<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Support\Branding\Contrast;

/**
 * What the card builder says once a card is saved (CHW-32, B6): published
 * or saved, with a warning when only pure white or black text reads on the
 * colour the card shows, its own or else its organization's (ADR 0007), as
 * the builder warns while it is picked.
 */
final readonly class DescribeCardSaved
{
    /**
     * @return array{type: 'success'|'warning', message: string}
     */
    public function handle(Organization $organization, LoyaltyCard $card, bool $published): array
    {
        $brand = $card->brandColor() ?? $organization->brand_color;

        if ($brand !== null && Contrast::isWeak($brand)) {
            return ['type' => 'warning', 'message' => $published
                ? __('Card published. Only pure white or black text reads on its colour: a darker or lighter shade will look better.')
                : __('Card saved. Only pure white or black text reads on its colour: a darker or lighter shade will look better.')];
        }

        return ['type' => 'success', 'message' => $published ? __('Card published.') : __('Card saved.')];
    }
}
