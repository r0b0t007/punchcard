<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\LoyaltyCard;

/**
 * Publishes or pauses a card (CHW-32, B7). A paused card refuses taps
 * (AddStamps) and is no longer offered to new customers; customers holding
 * it get the card the business runs now (CardChoice, CHW-148), and its
 * stamps stay on it for when it runs again. Runs in the tenant, as its org
 * admin: LoyaltyCard's guard decides.
 */
final readonly class SetCardActive
{
    public function handle(LoyaltyCard $card, bool $active): void
    {
        $card->forceFill(['active' => $active])->save();
    }
}
