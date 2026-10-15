<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\ConfirmByLink;
use App\Actions\Auth\DescribeConfirmLink;
use App\Actions\Auth\SendConfirmLink;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Confirm it's you" by email (CHW-23), for a signed-in account without a
 * password, where settings ask for one (RequirePassword, deleting the
 * account, choosing a password, a new address). Only an account that may
 * confirm so (MayConfirmByEmail).
 *
 * - POST /user/confirm-by-email (rate limited, confirm-link): a confirm
 *   link mailed to the account's own address (SendConfirmLink).
 * - GET /user/confirm-by-email/{link}: its page, one button, or why it
 *   can't be used (DescribeConfirmLink). Opening it spends nothing.
 * - POST /user/confirm-by-email/{link}: the button (ConfirmByLink): the
 *   session is confirmed, and back to where they were.
 */
final class ConfirmByEmailController extends Controller
{
    public function store(Request $request, SendConfirmLink $sendConfirmLink): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $sendConfirmLink->handle($user)
            ? back()->with('status', 'confirm-link-sent')
            : back()->withErrors(['email' => __('Confirm with your password or a passkey.')]);
    }

    public function show(Request $request, int $link, DescribeConfirmLink $describeConfirmLink): Response
    {
        /** @var User $user */
        $user = $request->user();

        return $this->linkPage($request, $request->hasValidSignature(absolute: false)
            ? $describeConfirmLink->handle($user, $link)
            : DescribeConfirmLink::EXPIRED);
    }

    public function confirm(Request $request, int $link, ConfirmByLink $confirmByLink, DescribeConfirmLink $describeConfirmLink): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // The signature first: whose a link is, only for a link the app signed.
        if (! $request->hasValidSignature(absolute: false)) {
            return $this->linkPage($request, DescribeConfirmLink::EXPIRED);
        }

        if (! $confirmByLink->handle($user, $link)) {
            $state = $describeConfirmLink->handle($user, $link);

            return $this->linkPage($request, $state === DescribeConfirmLink::READY ? DescribeConfirmLink::EXPIRED : $state);
        }

        $request->session()->passwordConfirmed();

        return redirect()->intended(route('profile.edit'));
    }

    /** @param  DescribeConfirmLink::READY|DescribeConfirmLink::EXPIRED|DescribeConfirmLink::OTHER_ACCOUNT  $state */
    private function linkPage(Request $request, string $state): Response
    {
        return Inertia::render('auth/confirm-link-open', [
            'state' => $state,
            // Its own path and query: never a host or scheme a proxy may have changed.
            'action' => $state === DescribeConfirmLink::READY ? $request->getRequestUri() : null,
        ]);
    }
}
