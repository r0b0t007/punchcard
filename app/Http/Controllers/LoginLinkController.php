<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\SignInCustomer;
use App\Actions\Auth\UseLoginLink;
use App\Http\Requests\LoginLinkRequest;
use App\Jobs\MailLoginLink;
use App\Models\LoginLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Signing in with an email link (CHW-23), for guests:
 *
 * - GET/POST /login/email (rate limited, login-link): the address, then a
 *   link mailed (SendLoginLink). Always "check your inbox", whatever the
 *   address, so the page never says who has an account.
 * - GET /login/link/{link}: the link's page, one button (or "expired" when
 *   it was used or is out of date). Opening it spends nothing, so a mail
 *   scanner that opens links can't use it up.
 * - POST /login/link/{link}: the button. The URL's signature (out of date
 *   after LoginLink::MINUTES), then the link spent once (UseLoginLink): a
 *   customer is signed in for a year and goes where they were going (a
 *   waiting tap: /t/claim); a new address finishes signing up first
 *   (FinishSignUpController). Otherwise "this link has expired".
 */
final class LoginLinkController extends Controller
{
    /** The address the last link proved, until when, for FinishSignUpController. */
    public const string PROVEN_EMAIL = 'sign-up.proven-email';

    public function create(Request $request): Response
    {
        return Inertia::render('auth/email-link', ['status' => $request->session()->get('status'), 'minutes' => LoginLink::MINUTES]);
    }

    /** The work on the queue (MailLoginLink): the answer takes as long for any address. */
    public function store(LoginLinkRequest $request): RedirectResponse
    {
        MailLoginLink::dispatch($request->email(), app()->getLocale());

        return back()->with('status', 'login-link-sent');
    }

    public function show(Request $request, int $link): Response
    {
        $loginLink = $request->hasValidSignature(absolute: false) ? LoginLink::query()->find($link) : null;

        return $this->linkPage($request, $loginLink?->isUsable() === true ? $loginLink : null);
    }

    public function signIn(Request $request, int $link, UseLoginLink $useLoginLink, SignInCustomer $signInCustomer): Response|RedirectResponse
    {
        $used = $request->hasValidSignature(absolute: false) ? $useLoginLink->handle($link) : null;

        if ($used === null) {
            return $this->linkPage($request, null);
        }

        if ($used['user'] === null) {
            // A new session id with the proven address: one planted beforehand never carries it.
            $request->session()->regenerate();
            $request->session()->put(self::PROVEN_EMAIL, ['email' => $used['email'], 'until' => now()->addMinutes(LoginLink::MINUTES)->getTimestamp()]);

            return to_route('sign-up.finish');
        }

        $signInCustomer->handle($request, $used['user']);

        return redirect()->intended(route('start'));
    }

    /** The link's page: its button and, half hidden, the account it signs in to; or, without a usable link, "expired". */
    private function linkPage(Request $request, ?LoginLink $link): Response
    {
        return Inertia::render('auth/email-link-open', [
            'expired' => ! $link instanceof LoginLink,
            // Its own path and query: never a host or scheme a proxy may have changed.
            'action' => $link instanceof LoginLink ? $request->getRequestUri() : null,
            'email' => $link?->maskedEmail(),
        ]);
    }
}
