<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Stamper;
use Illuminate\Support\Facades\DB;

/**
 * Takes a staff member off a business's staff (CHW-146): their membership
 * ends, their account stays (their stamps keep their name, and they may
 * belong to other businesses or collect stamps themselves). Staff only, in
 * the same statement that deletes, so an owner (or someone made one
 * meanwhile) is never removed here: owners are handed over (CHW-140). The
 * business is locked first, the order every staff Action locks in (inviting,
 * joining, cancelling). Through the guarded pivot model, in the owner's
 * tenant. Their next request is no longer in this business (ResolveTenant),
 * and an arming they made there ends (CHW-29).
 * Whether they were removed.
 */
final readonly class RemoveStaffMember
{
    public function handle(BusinessMember $membership): bool
    {
        return DB::transaction(function () use ($membership): bool {
            Business::query()->whereKey($membership->business_id)->lockForUpdate()->first();

            if (BusinessMember::query()->whereKey($membership->id)->where('role', BusinessRole::Staff)->delete() !== 1) {
                return false;
            }

            // An arming they made gives nothing once they are gone (CHW-29): its next tap gives one stamp.
            Stamper::query()->current()->where('business_id', $membership->business_id)->where('armed_by', $membership->user_id)
                ->update(['armed_qty' => null, 'armed_until' => null, 'armed_by' => null]);

            return true;
        });
    }
}
