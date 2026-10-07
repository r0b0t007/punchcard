<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CardBusiness;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;

/**
 * Which businesses honour a card (card_business, CHW-22): the org admin,
 * acting for the organization, decides; a franchisee never opts itself in
 * or out.
 */
final class CardBusinessPolicy
{
    use ReadsTenant;

    public function create(User $user, LoyaltyCard $card): bool
    {
        return $this->actsFor($card->organization_id);
    }

    public function delete(User $user, CardBusiness $participation): bool
    {
        return $this->actsFor($participation->organization_id);
    }
}
