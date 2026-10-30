<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\SelectTenant;
use App\Http\Middleware\SetTenant;
use App\Http\Requests\OpenBusinessRequest;
use App\Models\User;
use App\Support\Auth\MailedLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A business's page from its emails (CHW-295): the verification emails'
 * and the fraud alerts' signed link (MailedLink::toBusiness), for the user
 * it was mailed to (OpenBusinessRequest), selects the business it names,
 * as the tenant switcher would, then opens the page. Someone who doesn't
 * work there (any more), or a business that no longer operates, gets a 404
 * and keeps the business they had selected. Who may see the page is still
 * the page's own rule.
 *
 * Opened by another account (CHW-147, design 10e), it says whom it was sent
 * to, half hidden, and who is signed in, and offers to sign out: then the
 * sign-in page, which comes back to the link.
 */
final class OpenBusinessController extends Controller
{
    public function show(OpenBusinessRequest $request, int $business, string $page, SelectTenant $selectTenant): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        if (! $request->isForSignedInUser()) {
            return Inertia::render('auth/other-account', [
                'sentTo' => MailedLink::maskedEmail($request->recipient()->email),
                'signedInAs' => $user->email,
                'name' => $user->name,
                // The signed link itself, its own path and query, which "Sign out" posts to (switchAccount).
                'action' => $request->getRequestUri(),
            ])->toResponse($request)->setStatusCode(403);
        }

        if (! $selectTenant->handle($user, SetTenant::businessChoice($business))) {
            abort(404);
        }

        $request->session()->put(SetTenant::SESSION_KEY, SetTenant::businessChoice($business));

        return to_route(MailedLink::BUSINESS_PAGES[$page]);
    }

    /**
     * "Sign out and use that account", posted to the same signed link: signs out this browser only (never
     * logout(), which would sign the user out on every device they asked to be remembered on), then the
     * sign-in page. Already the right account (signed in in another tab): the link, opened.
     */
    public function switchAccount(OpenBusinessRequest $request): RedirectResponse
    {
        $link = MailedLink::onTheApp($request->getRequestUri());

        if ($request->isForSignedInUser()) {
            return redirect()->to($link);
        }

        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        redirect()->setIntendedUrl($link);

        return to_route('business.login');
    }
}
