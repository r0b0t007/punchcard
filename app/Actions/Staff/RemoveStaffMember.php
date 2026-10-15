<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\Business;
use App\Models\BusinessMember;

/**
 * Takes a staff member off a business's staff (CHW-146): their membership
 * ends, their account stays (their stamps keep their name, and they may
 * belong to other businesses or collect stamps themselves). Through the
 * guarded pivot (Business::members()), in the owner's tenant. Their next
 * request is no longer in this business (ResolveTenant).
 */
final readonly class RemoveStaffMember
{
    public function handle(BusinessMember $membership): void
    {
        Business::query()->findOrFail($membership->business_id)->members()->detach($membership->user_id);
    }
}
