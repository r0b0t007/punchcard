<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\BusinessRole;
use App\Support\Tenancy\TenantContext;

/**
 * What the user may do where they work right now (CHW-22): policies read the
 * request's TenantContext (ResolveTenant), never "this user has a role
 * somewhere", so staff of B1 who are org admins of A have no rights in B.
 * A platform admin passes only inside Filament (Gate::before).
 */
trait ReadsTenant
{
    private function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** The user works in this business right now (any role). */
    private function worksIn(int $businessId): bool
    {
        return $this->tenant()->businessId() === $businessId
            && ($this->tenant()->businessRole() instanceof BusinessRole || $this->tenant()->isOrgAdmin());
    }

    /** The user administers this organization right now. */
    private function administers(int $organizationId): bool
    {
        return $this->tenant()->organizationId() === $organizationId && $this->tenant()->isOrgAdmin();
    }

    /** The user sees this business: they work in it, or they administer its organization across all its businesses. */
    private function sees(int $organizationId, int $businessId): bool
    {
        return $this->worksIn($businessId)
            || ($this->administers($organizationId) && $this->tenant()->businessId() === null);
    }

    /** The user runs this business: its owner, or its org admin, working in it or across the organization. */
    private function runs(int $organizationId, int $businessId): bool
    {
        if ($this->tenant()->businessId() === $businessId) {
            return $this->tenant()->businessRole() === BusinessRole::Owner || $this->tenant()->isOrgAdmin();
        }

        return $this->administers($organizationId) && $this->tenant()->businessId() === null;
    }
}
