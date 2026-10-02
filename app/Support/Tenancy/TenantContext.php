<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

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

    private int $bypassDepth = 0;

    public function set(Organization $organization, ?Business $business = null): void
    {
        if ($business instanceof Business && (int) $business->organization_id !== (int) $organization->id) {
            throw new LogicException('The business does not belong to the organization.');
        }

        $this->organizationId = $organization->id;
        $this->businessId = $business?->id;
    }

    public function clear(): void
    {
        $this->organizationId = null;
        $this->businessId = null;
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
