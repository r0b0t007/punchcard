<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Staff\CancelStaffInvitation;
use App\Actions\Staff\DescribeStaffPage;
use App\Actions\Staff\InviteStaff;
use App\Actions\Staff\RemoveStaffMember;
use App\Http\Requests\Staff\InviteStaffRequest;
use App\Models\Business;
use App\Models\BusinessMember;
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
 * - GET /business/staff: who works there, the invite form and the
 *   invitations still pending (DescribeStaffPage).
 * - POST /business/staff/invitations (throttled, staff-invite): an
 *   invitation mailed (InviteStaff).
 * - DELETE /business/staff/invitations/{invitation}: one cancelled
 *   (CancelStaffInvitation).
 * - DELETE /business/staff/{member}: a staff member removed, never their
 *   account (RemoveStaffMember); owners are handed over instead (CHW-140).
 *
 * {member} and {invitation} resolve in the tenant: another business's is a 404.
 */
final class StaffController extends Controller
{
    public function index(TenantContext $context, DescribeStaffPage $describeStaffPage): Response
    {
        $business = $this->business($context);
        Gate::authorize('viewAny', [BusinessMember::class, $business]);

        return Inertia::render('business/staff', $describeStaffPage->handle($business));
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

    public function cancelInvitation(int $invitation, TenantContext $context, CancelStaffInvitation $cancelStaffInvitation): RedirectResponse
    {
        $business = $this->business($context);
        $staffInvitation = StaffInvitation::query()->whereKey($invitation)->where('business_id', $business->id)->first() ?? abort(404);
        Gate::authorize('delete', $staffInvitation);

        Inertia::flash('toast', $cancelStaffInvitation->handle($staffInvitation)
            ? ['type' => 'success', 'message' => __('Invitation cancelled.')]
            : ['type' => 'error', 'message' => __('That invitation was already used or has run out.')]);

        return to_route('staff.index');
    }

    public function destroy(int $member, TenantContext $context, RemoveStaffMember $removeStaffMember): RedirectResponse
    {
        $business = $this->business($context);
        $membership = BusinessMember::query()->whereKey($member)->where('business_id', $business->id)->first() ?? abort(404);
        Gate::authorize('delete', $membership);

        // Staff only (RemoveStaffMember): an owner is handed over (CHW-140), never removed here.
        if (! $removeStaffMember->handle($membership)) {
            abort(403);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Removed from your staff.')]);

        return to_route('staff.index');
    }

    /** The business worked in (TenantContext); none (an org admin across the organization) is a 403. */
    private function business(TenantContext $context): Business
    {
        $business = $context->businessId() === null ? null : Business::query()->find($context->businessId());

        if (! $business instanceof Business) {
            abort(403);
        }

        return $business;
    }
}
