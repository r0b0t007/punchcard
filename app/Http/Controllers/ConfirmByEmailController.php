<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\MaySignInByLink;
use App\Models\LoginLink;
use App\Models\User;
use App\Notifications\ConfirmLinkMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Confirm it's you" by email (CHW-23), for a signed-in account without a
 * password, where settings ask for one (RequirePassword, deleting the
 * account). Only an account a link could sign in anyway (MaySignInByLink:
 * a confirmed address, no second factor, no role): for anyone else the
 * inbox would stand in for a password or a second factor, so they use
 * those (or a passkey).
 *
 * - POST /user/confirm-by-email (rate limited, confirm-link): a confirm
 *   link mailed to the account's own address.
 * - GET /user/confirm-by-email/{link}: its page, one button (or
 *   "expired"). Opening it spends nothing.
 * - POST /user/confirm-by-email/{link}: the button. The signature, then the
 *   link spent once as a confirm link (never a sign-in one), by the account
 *   it was mailed to: the session is confirmed, and back to where they were.
 */
final class ConfirmByEmailController extends Controller
{
    public function __construct(private readonly MaySignInByLink $maySignInByLink) {}

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->mayConfirmByEmail($user)) {
            return back()->withErrors(['email' => __('Confirm with your password or a passkey.')]);
        }

        $user->notify(new ConfirmLinkMail(LoginLink::for($user->email, LoginLink::CONFIRM)->signedUrl()));

        return back()->with('status', 'confirm-link-sent');
    }

    public function show(Request $request, int $link): Response
    {
        return $this->linkPage($request, $request->hasValidSignature(absolute: false) && LoginLink::usable($link, LoginLink::CONFIRM) instanceof LoginLink);
    }

    public function confirm(Request $request, int $link): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        // Only by the account it was mailed to, if it may confirm so: anyone else's attempt leaves it to its owner.
        $email = $request->hasValidSignature(absolute: false) && $this->mayConfirmByEmail($user)
            ? LoginLink::spend($link, LoginLink::CONFIRM, $user->email)
            : null;

        if ($email === null) {
            return $this->linkPage($request, usable: false);
        }

        $request->session()->passwordConfirmed();

        return redirect()->intended(route('profile.edit'));
    }

    private function mayConfirmByEmail(User $user): bool
    {
        return $user->password === null && $this->maySignInByLink->handle($user);
    }

    private function linkPage(Request $request, bool $usable): Response
    {
        return Inertia::render('auth/confirm-link-open', [
            'expired' => ! $usable,
            // Its own path and query: never a host or scheme a proxy may have changed.
            'action' => $usable ? $request->getRequestUri() : null,
        ]);
    }
}
