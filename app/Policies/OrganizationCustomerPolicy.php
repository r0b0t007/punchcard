<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationCustomer;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;

/**
 * A franchise's customers across its sites (CHW-365, B4 and B5 at network level): its org admin, working across the
 * network, and no one else (a franchisee sees their own business's customers, BusinessCustomerPolicy). Read only:
 * stamps and notes are each business's to change.
 */
final class OrganizationCustomerPolicy
{
    use ReadsTenant;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->atNetworkOf($organization->id);
    }

    public function view(User $user, OrganizationCustomer $customer): bool
    {
        return $this->atNetworkOf($customer->organization_id);
    }

    /** The org admin of this organization, working across it rather than inside one of its businesses. */
    private function atNetworkOf(int $organizationId): bool
    {
        return $this->tenant()->businessId() === null && $this->administers($organizationId);
    }
}
