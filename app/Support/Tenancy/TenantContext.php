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
 * Bound as a scoped service: a fresh, empty context per request or job. A
 * queued job runs in the tenant it was dispatched from, without the user's
 * rights or bypass() (QueuedTenant).
 */
final class TenantContext
{
    private ?int $organizationId = null;

    private ?int $businessId = null;

    private bool $orgAdmin = false;

    private bool $ownsTheAccount = false;

    private ?BusinessRole $businessRole = null;

    private ?int $locationId = null;

    private int $bypassDepth = 0;

    /**
     * Sets the tenant and what the user may do in it. ResolveTenant passes both
     * from the user's memberships. Without them the context grants no rights
     * (fail closed): reads work, while writes to the tenant structure (the
     * organization, its businesses, memberships) that need an owner or org admin
     * throw. Operational site data (locations, stampers) is authorized by policies
     * (CHW-22), which read these rights for the current tenant only.
     * Code acting for no user sets the tenant without rights (queued jobs get the
     * dispatching tenant this way, through QueuedTenant), or uses bypass() when
     * it spans tenants.
     *
     * @param  bool  $orgAdmin  the user administers the organization (organization_user)
     * @param  BusinessRole|null  $businessRole  the user's role in the business (business_user)
     * @param  int|null  $locationId  the one location a staff member is limited to (business_user)
     * @param  bool  $ownsTheAccount  the user owns the only business of an independent café or chain: its org admin without an organization_user row
     */
    public function set(Organization $organization, ?Business $business = null, bool $orgAdmin = false, ?BusinessRole $businessRole = null, ?int $locationId = null, bool $ownsTheAccount = false): void
    {
        if ($business instanceof Business && (int) $business->organization_id !== (int) $organization->id) {
            throw new LogicException('The business does not belong to the organization.');
        }

        $this->organizationId = $organization->id;
        $this->businessId = $business?->id;
        $this->orgAdmin = $orgAdmin;
        $this->businessRole = $business instanceof Business ? $businessRole : null;
        $this->locationId = $business instanceof Business ? $locationId : null;
        $this->ownsTheAccount = $business instanceof Business && $ownsTheAccount;
    }

    public function clear(): void
    {
        $this->organizationId = null;
        $this->businessId = null;
        $this->orgAdmin = false;
        $this->businessRole = null;
        $this->locationId = null;
        $this->ownsTheAccount = false;
    }

    /** The user administers the current organization (franchise HQ, or an independent café's owner). */
    public function isOrgAdmin(): bool
    {
        return $this->orgAdmin || $this->ownsTheAccount;
    }

    /** The user owns the only business of an independent café or chain: the business is the organization. */
    public function ownsTheAccount(): bool
    {
        return $this->ownsTheAccount;
    }

    /**
     * The user holds an org_admin row here, so they manage the organization's
     * admins. Owning the account (an independent café's co-owner) runs the
     * organization but never grants a row: it would outlive their removal
     * from the business (CHW-22).
     */
    public function managesOrgAdmins(): bool
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

    /** The one location the user works at, when their membership limits them to it; null for the whole business. */
    public function locationId(): ?int
    {
        return $this->locationId;
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

    /**
     * A copy of the whole state (tenant, rights, bypass depth), for code that
     * runs something in another tenant and must put this one back (QueuedTenant).
     */
    public function snapshot(): self
    {
        return clone $this;
    }

    /** Puts back a snapshot(); `new TenantContext` restores the empty context. */
    public function restore(self $snapshot): void
    {
        $this->organizationId = $snapshot->organizationId;
        $this->businessId = $snapshot->businessId;
        $this->orgAdmin = $snapshot->orgAdmin;
        $this->businessRole = $snapshot->businessRole;
        $this->locationId = $snapshot->locationId;
        $this->ownsTheAccount = $snapshot->ownsTheAccount;
        $this->bypassDepth = $snapshot->bypassDepth;
    }
}
