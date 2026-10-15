<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\StaffInvitation;

/**
 * Cancels an invitation still waiting (CHW-146): its link stops working
 * (FindStaffInvitation finds nothing). In the owner's tenant; the model's
 * guards check they may manage the staff.
 */
final readonly class CancelStaffInvitation
{
    public function handle(StaffInvitation $invitation): void
    {
        $invitation->delete();
    }
}
