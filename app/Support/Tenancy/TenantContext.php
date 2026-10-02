<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Organization;
use Closure;
use LogicException;

/**
 * The tenant the current request works in (ADR 0006): an organization, and for
 * owners and staff one of its businesses. Tenant-scoped models read it.
 *
 * Fail closed: with no tenant set, scoped queries return nothing and tenant
 * writes throw. Code that must span tenants (signup, the tap endpoint's
 * stamper lookup, admin, seeders) calls bypass() explicitly, so every such
 * place is searchable. Inside bypass() the scopes and the "must match the
 * current tenant" checks are off; consistency (a location's organization is
 * its business's, tenant ids never change) is still enforced.
 *
 * Bound as a scoped service: a fresh, empty context per request or job.
 */
final class TenantContext
{
    private ?int $organizationId = null;

    private ?int $businessId = null;

    private bool $orgAdmin = false;

    private ?BusinessRole $businessRole = null;

    private int $bypassDepth = 0;

    /**
     * Sets the tenant and what the user may do in it. ResolveTenant passes both
     * from the user's memberships. Without them the context grants no rights
     * (fail closed): reads work, while writes to the tenant structure (the
     * organization, its businesses, memberships) that need an owner or org admin
     * throw. Operational site data (locations, stampers) is authorized by policies.
     * Code acting for no user (jobs, the tap endpoint, white-label lookups) uses
     * bypass(), never set().
     *
     * @param  bool  $orgAdmin  the user administers the organization (organization_user)
     * @param  BusinessRole|null  $businessRole  the user's role in the business (business_user)
     */
    public function set(Organization $organization, ?Business $business = null, bool $orgAdmin = false, ?BusinessRole $businessRole = null): void
    {
        if ($business instanceof Business && (int) $business->organization_id !== (int) $organization->id) {
            throw new LogicException('The business does not belong to the organization.');
        }

        $this->organizationId = $organization->id;
        $this->businessId = $business?->id;
        $this->orgAdmin = $orgAdmin;
        $this->businessRole = $business instanceof Business ? $businessRole : null;
    }

    public function clear(): void
    {
        $this->organizationId = null;
        $this->businessId = null;
        $this->orgAdmin = false;
        $this->businessRole = null;
    }

    /** The user administers the current organization (franchise HQ, or an independent café's owner). */
    public function isOrgAdmin(): bool
    {
        return $this->orgAdmin;
    }

    /** The user's role in the current business, if any. */
    public function businessRole(): ?BusinessRole
    {
        return $this->businessRole;
    }

    /** Owners and org admins manage staff; staff do not, not even their own row. */
    public function canManageMembers(): bool
    {
        return $this->orgAdmin || $this->businessRole === BusinessRole::Owner;
    }

    public function organizationId(): ?int
    {
        return $this->organizationId;
    }

    public function businessId(): ?int
    {
        return $this->businessId;
    }

    /**
     * Runs the callback with tenant scopes switched off.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function bypass(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }
}
