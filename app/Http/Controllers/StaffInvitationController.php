<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Staff\DescribeStaffInvitation;
use App\Actions\Staff\JoinStaff;
use App\Http\Middleware\SetTenant;
use App\Http\Requests\Staff\AcceptStaffInvitationRequest;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Support\Auth\MailedLink;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The link of an invitation to a business's staff (CHW-146), opened from
 * the invited inbox, signed in or not:
 *
 * - GET /staff-invitations/{invitation}: its page: join, choose a password
 *   then join, sign in first, sign up, another account's, or expired.
 *   Opening it changes nothing: it spends nothing, and never decides where
 *   a later sign-in lands.
 * - POST /staff-invitations/{invitation}: the button: signing in first (and
 *   coming back here, unless a tap waits to be claimed), or a new account,
 *   or a first password, then onto the staff, all or nothing (JoinStaff),
 *   and into that business.
 */
final class StaffInvitationController extends Controller
{
    public function show(AcceptStaffInvitationRequest $request): Response
    {
        return $this->page($request, $request->invitation(), $request->state());
    }

    public function accept(AcceptStaffInvitationRequest $request, JoinStaff $joinStaff): Response|RedirectResponse
    {
        $invitation = $request->invitation();
        $state = $request->state();

        if (! $invitation instanceof StaffInvitation || $state === null || $state === DescribeStaffInvitation::OTHER_ACCOUNT) {
            return $this->page($request, $invitation, $state);
        }

        if ($state === DescribeStaffInvitation::SIGN_IN) {
            $this->comeBackHere($request);

            return to_route('login');
        }

        $signedIn = $request->user();
        $user = $joinStaff->handle($invitation, $state, $signedIn instanceof User ? $signedIn : null, [
            'name' => (string) $request->validated('name', ''),
            'password' => (string) $request->validated('password', ''),
            'password_confirmation' => (string) $request->input('password_confirmation', ''),
            'adult' => (string) $request->validated('adult', ''),
            'marketing' => (string) $request->validated('marketing', ''),
        ], $request->session()->getId());

        if (! $user instanceof User) {
            return $this->page($request, null, null);
        }

        if ($state === DescribeStaffInvitation::SIGN_UP) {
            event(new Registered($user));
            Auth::login($user);
            $request->session()->regenerate();
        }

        $request->session()->put(SetTenant::SESSION_KEY, 'business:'.$invitation->business_id);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('You\'re on the staff of :business.', ['business' => $invitation->business->name])]);

        return to_route('dashboard');
    }

    /**
     * Signing in (password, passkey or email link) comes back to this link: on the app's own address (app.url),
     * never a host or scheme a proxy may have changed. A tap waiting to be claimed comes first (as on the join
     * page): its stamp is never lost to an invitation.
     */
    private function comeBackHere(AcceptStaffInvitationRequest $request): void
    {
        $intended = $request->session()->get('url.intended');

        if (! is_string($intended) || parse_url($intended, PHP_URL_PATH) !== parse_url(route('taps.claim'), PHP_URL_PATH)) {
            redirect()->setIntendedUrl(MailedLink::onTheApp($request->getRequestUri()));
        }
    }

    private function page(AcceptStaffInvitationRequest $request, ?StaffInvitation $invitation, ?string $state): Response
    {
        $usable = $invitation instanceof StaffInvitation && $state !== null;
        $isTheirs = in_array($state, [DescribeStaffInvitation::JOIN, DescribeStaffInvitation::CHOOSE_PASSWORD, DescribeStaffInvitation::SIGN_UP], true);

        return Inertia::render('auth/staff-invitation', [
            'state' => $usable ? $state : 'expired',
            'businessName' => $usable ? $invitation->business->name : null,
            'locationName' => $usable ? $invitation->location?->name : null,
            // In full to whoever it is for; half hidden to anyone else.
            'email' => $usable ? ($isTheirs ? $invitation->email : $invitation->maskedEmail()) : null,
            // Its own path and query: never a host or scheme a proxy may have changed.
            'action' => $usable ? $request->getRequestUri() : null,
        ]);
    }
}
