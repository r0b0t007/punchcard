<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;

/**
 * An organization's admins (franchise HQ, CHW-22): only its org admins see
 * or change them, so a franchisee never lists HQ's people. Only one holding
 * an org_admin row: an independent café's co-owner runs the organization but
 * never grants themselves a row that would outlive their removal. An
 * organization always keeps an org admin.
 */
final class OrganizationMemberPolicy
{
    use ReadsTenant;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->administers($organization->id) && $this->tenant()->managesOrgAdmins();
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->administers($organization->id) && $this->tenant()->managesOrgAdmins();
    }

    public function delete(User $user, OrganizationMember $member): bool
    {
        return $this->administers($member->organization_id)
            && $this->tenant()->managesOrgAdmins()
            && $this->tenant()->bypass(fn (): int => OrganizationMember::query()
                ->where('organization_id', $member->organization_id)
                ->where('role', OrganizationRole::OrgAdmin)
                ->count()) > 1;
    }
}
