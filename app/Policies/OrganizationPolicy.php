<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BillingEntity;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;

/**
 * The organization (ADR 0006, CHW-22). Its org admin runs it: the brand, the
 * card program, and billing when the organization pays. The owner of an
 * independent café or a chain is its org admin (ResolveTenant). A franchisee
 * sees its organization but never changes it.
 */
final class OrganizationPolicy
{
    use ReadsTenant;

    public function view(User $user, Organization $organization): bool
    {
        return $this->tenant()->organizationId() === $organization->id;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->actsFor($organization->id);
    }

    public function manageBrand(User $user, Organization $organization): bool
    {
        return $this->actsFor($organization->id);
    }

    /** Billing is the org admin's when the organization pays for its businesses; otherwise each owner's (BusinessPolicy). */
    public function manageBilling(User $user, Organization $organization): bool
    {
        return $this->actsFor($organization->id) && $organization->billing_entity === BillingEntity::Organization;
    }

    /** The franchise console (B18): HQ only. */
    public function viewNetwork(User $user, Organization $organization): bool
    {
        return $this->actsFor($organization->id) && $organization->type === OrganizationType::Franchise;
    }

    public function inviteFranchisee(User $user, Organization $organization): bool
    {
        return $this->viewNetwork($user, $organization);
    }
}
