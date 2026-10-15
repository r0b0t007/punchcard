<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Joins the staff an invitation is for (CHW-146), as the account with its
 * address: once, while pending, and only while the business and location
 * are open. The business, organization and location come from the
 * invitation, never from the request. In bypass(): the account is not on
 * that staff yet, and the owner who invited allowed it. An existing member
 * keeps their role (an owner is never demoted). The address is confirmed:
 * the link came to its inbox. Whether it joined.
 */
final readonly class AcceptStaffInvitation
{
    public function __construct(private TenantContext $context) {}

    public function handle(int $invitationId, User $user): bool
    {
        return $this->context->bypass(fn (): bool => DB::transaction(function () use ($invitationId, $user): bool {
            // The business first, then the invitation: the order InviteStaff locks them in, so the two never deadlock.
            $site = StaffInvitation::query()->whereKey($invitationId)->first(['business_id', 'location_id']);

            if (! $site instanceof StaffInvitation || ! ArchivedSites::isOpen($site->business_id, $site->location_id, lock: true)) {
                return false;
            }

            $invitation = StaffInvitation::query()->whereKey($invitationId)->pending()->lockForUpdate()->first();

            if (! $invitation instanceof StaffInvitation || $invitation->email !== $user->email) {
                return false;
            }

            $alreadyMember = BusinessMember::query()->where('business_id', $invitation->business_id)->where('user_id', $user->id)->exists();

            if (! $alreadyMember) {
                Business::query()->findOrFail($invitation->business_id)->members()->attach($user, [
                    'role' => BusinessRole::Staff->value,
                    'location_id' => $invitation->location_id,
                ]);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            return true;
        }));
    }
}
