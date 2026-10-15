<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\ConfirmByLink;
use App\Actions\Auth\MayConfirmByEmail;
use App\Actions\Auth\SendConfirmLink;
use App\Models\LoginLink;
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
 *   can't be used (out of date or used, another account's). Opening it
 *   spends nothing.
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

    public function show(Request $request, int $link, MayConfirmByEmail $mayConfirmByEmail): Response
    {
        /** @var User $user */
        $user = $request->user();
        $confirmLink = $request->hasValidSignature(absolute: false) && $mayConfirmByEmail->handle($user)
            ? LoginLink::query()->whereKey($link)->usable(LoginLink::CONFIRM)->first()
            : null;

        return match (true) {
            ! $confirmLink instanceof LoginLink => $this->linkPage($request, 'expired'),
            $confirmLink->email !== $user->email => $this->linkPage($request, 'other-account'),
            default => $this->linkPage($request, null),
        };
    }

    public function confirm(Request $request, int $link, ConfirmByLink $confirmByLink): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $request->hasValidSignature(absolute: false) || ! $confirmByLink->handle($user, $link)) {
            $mailedTo = LoginLink::query()->whereKey($link)->value('email');

            return $this->linkPage($request, is_string($mailedTo) && $mailedTo !== $user->email ? 'other-account' : 'expired');
        }

        $request->session()->passwordConfirmed();

        return redirect()->intended(route('profile.edit'));
    }

    /** @param  'expired'|'other-account'|null  $refused */
    private function linkPage(Request $request, ?string $refused): Response
    {
        return Inertia::render('auth/confirm-link-open', [
            'expired' => $refused !== null,
            'reason' => $refused,
            // Its own path and query: never a host or scheme a proxy may have changed.
            'action' => $refused === null ? $request->getRequestUri() : null,
        ]);
    }
}
