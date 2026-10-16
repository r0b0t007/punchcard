<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\Business;
use App\Models\StaffInvitation;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an invitation still waiting (CHW-146): its link stops working
 * (FindStaffInvitation finds nothing). The business is locked first, the
 * order InviteStaff and AcceptStaffInvitation lock in, so a cancel and a
 * join never cross: one waits for the other, and an invitation used
 * meanwhile stays (with its member). In the owner's tenant; the model's
 * guards check they manage the staff. Whether it was cancelled.
 */
final readonly class CancelStaffInvitation
{
    public function handle(StaffInvitation $invitation): bool
    {
        return DB::transaction(function () use ($invitation): bool {
            Business::query()->whereKey($invitation->business_id)->lockForUpdate()->first();

            return StaffInvitation::query()->whereKey($invitation->id)->pending()->delete() === 1;
        });
    }
}
