<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StaffInvitation;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;

/**
 * Invitations to a business's staff (CHW-146): whoever runs the business
 * cancels one, as they manage its staff. Its own ability, so a later limit
 * on inviting (a staff quota) never keeps an owner from cancelling.
 */
final class StaffInvitationPolicy
{
    use ReadsTenant;

    public function delete(User $user, StaffInvitation $invitation): bool
    {
        return $this->runs($invitation->organization_id, $invitation->business_id);
    }
}
