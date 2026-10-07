<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;

/**
 * A business's people (B13, CHW-22): its owner or org admin manages them;
 * staff manage nobody, not even their own row (BusinessMember guards the
 * same at the model layer). A business always keeps an owner: the last one
 * is never removed or made staff (MustHandOverBusiness hands it over first).
 */
final class BusinessMemberPolicy
{
    use ReadsTenant;

    public function viewAny(User $user, Business $business): bool
    {
        return $this->runs($business->organization_id, $business->id);
    }

    public function create(User $user, Business $business): bool
    {
        return $this->runs($business->organization_id, $business->id);
    }

    /** A change of location; a change of role is changeRole(). */
    public function update(User $user, BusinessMember $member): bool
    {
        return $this->runs($member->organization_id, $member->business_id);
    }

    public function changeRole(User $user, BusinessMember $member, BusinessRole $role): bool
    {
        return $this->runs($member->organization_id, $member->business_id)
            && ($role === BusinessRole::Owner || ! $this->isTheLastOwner($member));
    }

    public function delete(User $user, BusinessMember $member): bool
    {
        return $this->runs($member->organization_id, $member->business_id) && ! $this->isTheLastOwner($member);
    }

    private function isTheLastOwner(BusinessMember $member): bool
    {
        return $member->role === BusinessRole::Owner
            && $this->tenant()->bypass(fn (): int => BusinessMember::query()
                ->where('business_id', $member->business_id)
                ->where('role', BusinessRole::Owner)
                ->count()) === 1;
    }
}
