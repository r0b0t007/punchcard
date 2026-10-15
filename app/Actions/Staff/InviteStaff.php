<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\Business;
use App\Models\Location;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Invites someone to the current business's staff (CHW-146), optionally to
 * one location: an invitation good for StaffInvitation::DAYS, its link mailed
 * to the address in the page's language. A later invitation to the same
 * address replaces a pending one, whose link then stops working: with the
 * business locked, so two quick sends never leave two links. Run by an
 * owner, in their tenant (the model's guards check it).
 */
final readonly class InviteStaff
{
    public function handle(Business $business, User $invitedBy, string $email, ?int $locationId, string $locale): StaffInvitation
    {
        $invitation = DB::transaction(function () use ($business, $invitedBy, $email, $locationId): StaffInvitation {
            Business::query()->whereKey($business->id)->lockForUpdate()->first();
            StaffInvitation::query()->where('business_id', $business->id)->where('email', $email)->pending()->delete();

            $invitation = (new StaffInvitation)->forceFill([
                'business_id' => $business->id,
                'location_id' => $locationId,
                'email' => $email,
                'invited_by' => $invitedBy->id,
                'expires_at' => now()->addDays(StaffInvitation::DAYS),
            ]);
            $invitation->save();

            return $invitation;
        });

        $location = $locationId === null ? null : Location::query()->whereKey($locationId)->value('name');

        Notification::route('mail', $email)->notify(
            (new StaffInvitationMail($invitation->signedUrl(), $business->name, is_string($location) ? $location : null))->locale($locale),
        );

        return $invitation;
    }
}
