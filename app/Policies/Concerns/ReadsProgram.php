<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\Reward;

/**
 * The card program around the tenant (ADR 0006, CHW-22): which businesses
 * honour a card (in bypass(), whatever the caller's scope), and which
 * customers the caller manages (through the caller's own scope).
 */
trait ReadsProgram
{
    use ReadsTenant;

    /** The business honours the card (LoyaltyCard::honouredBy). */
    private function honours(int $cardId, int $businessId): bool
    {
        return $this->tenant()->bypass(fn (): bool => LoyaltyCard::query()->honouredBy($businessId)->whereKey($cardId)->exists());
    }

    /** The user manages customers where they work now: the org admin, or the owner of the business. */
    private function managesCustomers(): bool
    {
        $organizationId = $this->tenant()->organizationId();
        $businessId = $this->tenant()->businessId();

        return $organizationId !== null
            && ($this->administers($organizationId) || ($businessId !== null && $this->runs($organizationId, $businessId)));
    }

    /**
     * The user manages customers where they work, and the tenant scope shows
     * them this row: the very query TenantScope runs, so a policy never drifts
     * from what a list shows (the org admin the program, HQ at its own site
     * included; an owner the customers who were there and the rewards
     * redeemed there).
     *
     * @param  class-string<CardEnrollment|Reward>  $class
     */
    private function managesAndSees(string $class, int $id): bool
    {
        return $this->managesCustomers() && $class::query()->whereKey($id)->exists();
    }
}
