<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ReadsProgram;
use Illuminate\Auth\Access\Response;

/**
 * The card program (ADR 0006, CHW-22). The org admin, acting for the
 * organization, runs it: the card, its look, and its rules (cooldown and
 * daily cap), which every honouring business shares. The owner of an
 * independent café's or chain's only business is that org admin. A
 * franchisee sees the card it honours but never changes it. Once customers
 * hold the card, its mode and tiers are fixed and it is switched off, never
 * deleted (LoyaltyCard, the database).
 */
final class LoyaltyCardPolicy
{
    use ReadsProgram;

    public function view(User $user, LoyaltyCard $card): bool
    {
        $businessId = $this->tenant()->businessId();

        return $this->actsFor($card->organization_id)
            || ($businessId !== null && $this->worksIn($businessId) && $this->honours($card->id, $businessId));
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->actsFor($organization->id);
    }

    public function update(User $user, LoyaltyCard $card): bool
    {
        return $this->actsFor($card->organization_id);
    }

    /** Cooldown and daily cap: the fraud settings (B16), which apply from the next stamp. */
    public function updateRules(User $user, LoyaltyCard $card): bool
    {
        return $this->actsFor($card->organization_id);
    }

    /** Mode and tiers: fixed once customers hold the card. */
    public function changeMode(User $user, LoyaltyCard $card): Response
    {
        return $this->actsFor($card->organization_id) && ! $this->isHeld($card)
            ? Response::allow()
            : Response::deny('Customers hold this card: its mode and tiers are fixed.');
    }

    public function delete(User $user, LoyaltyCard $card): Response
    {
        return $this->actsFor($card->organization_id) && ! $this->isHeld($card)
            ? Response::allow()
            : Response::deny('Customers hold this card: switch it off instead.');
    }

    private function isHeld(LoyaltyCard $card): bool
    {
        return $this->tenant()->bypass(fn (): bool => CardEnrollment::query()->where('card_id', $card->id)->exists());
    }
}
