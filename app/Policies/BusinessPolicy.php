<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BillingEntity;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;

/**
 * A business (ADR 0006, CHW-22). Its people see it; its owner, or its
 * organization's org admin, runs it: details, stats, customers. Billing is
 * the owner's when each business pays for itself. Staff never change it.
 */
final class BusinessPolicy
{
    use ReadsTenant;

    public function view(User $user, Business $business): bool
    {
        return $this->sees($business->organization_id, $business->id);
    }

    public function update(User $user, Business $business): bool
    {
        return $this->runs($business->organization_id, $business->id);
    }

    public function viewStats(User $user, Business $business): bool
    {
        return $this->runs($business->organization_id, $business->id);
    }

    public function viewCustomers(User $user, Business $business): bool
    {
        return $this->runs($business->organization_id, $business->id);
    }

    /** The owner pays when each business pays for itself; when the organization pays, see OrganizationPolicy. */
    public function manageBilling(User $user, Business $business): bool
    {
        return $this->tenant()->businessId() === $business->id
            && $this->tenant()->businessRole() === BusinessRole::Owner
            && $this->tenant()->bypass(fn (): Organization => $business->organization)->billing_entity === BillingEntity::Business;
    }
}
