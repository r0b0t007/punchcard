<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Stamper;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;

/**
 * A business's stampers (CHW-22). Its people see them. Its owner or org
 * admin renames, disables or moves one; registering and unassigning a tag
 * are the platform admin's (Stamper, in bypass()). Anyone working there arms
 * one at the counter, staff limited to one location only the stampers there.
 */
final class StamperPolicy
{
    use ReadsTenant;

    public function view(User $user, Stamper $stamper): bool
    {
        return $this->sees($stamper->organization_id, $stamper->business_id);
    }

    public function update(User $user, Stamper $stamper): bool
    {
        return $this->runs($stamper->organization_id, $stamper->business_id);
    }

    public function arm(User $user, Stamper $stamper): bool
    {
        $limitedTo = $this->tenant()->locationId();

        return $this->worksIn($stamper->business_id)
            && ($limitedTo === null || $limitedTo === $stamper->location_id);
    }
}
