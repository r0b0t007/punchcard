<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Staff\InviteStaff;
use App\Http\Requests\Staff\InviteStaffRequest;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Location;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A business's staff (CHW-146, B13 for the pilot), its owner's:
 *
 * - GET /business/staff: the invite form and the invitations still pending.
 * - POST /business/staff/invitations (throttled, staff-invite): an
 *   invitation mailed (InviteStaff).
 */
final class StaffController extends Controller
{
    public function index(TenantContext $context): Response
    {
        $business = $context->businessId() === null ? null : Business::query()->find($context->businessId());

        if (! $business instanceof Business) {
            abort(403);
        }

        Gate::authorize('viewAny', [BusinessMember::class, $business]);

        return Inertia::render('business/staff', [
            'locations' => Location::query()->where('business_id', $business->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'invitations' => StaffInvitation::query()
                ->where('business_id', $business->id)
                ->pending()
                ->with('location:id,name')
                ->latest('id')
                ->get()
                ->map(fn (StaffInvitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'location' => $invitation->location?->name,
                    'expiresAt' => $invitation->expires_at->toIso8601String(),
                ]),
        ]);
    }

    public function invite(InviteStaffRequest $request, InviteStaff $inviteStaff): RedirectResponse
    {
        /** @var Business $business */
        $business = $request->business();
        /** @var User $owner */
        $owner = $request->user();
        $email = (string) $request->validated('email');

        $inviteStaff->handle($business, $owner, $email, $request->locationId(), app()->getLocale());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $email])]);

        return to_route('staff.index');
    }
}
