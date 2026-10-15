<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\ApplyEmailChange;
use App\Actions\Auth\CancelEmailChange;
use App\Actions\Auth\FindPendingEmailChange;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The link mailed to a new address asked for in settings (CHW-23,
 * RequestEmailChange), opened from that inbox, signed in or not:
 *
 * - GET /email/confirm/{user}: its page, one button (opening it spends
 *   nothing), or "expired" (out of date, used, replaced by a later request
 *   or cancelled): FindPendingEmailChange.
 * - POST /email/confirm/{user}: the button (ApplyEmailChange): the account
 *   has that address from now on, unless another account took it meanwhile.
 *
 * And, from settings, DELETE /settings/email-change: the new address
 * dropped before it is confirmed (CancelEmailChange).
 */
final class EmailChangeController extends Controller
{
    public function show(Request $request, int $user, FindPendingEmailChange $findPendingEmailChange): Response
    {
        $waiting = $request->hasValidSignature(absolute: false)
            && $findPendingEmailChange->handle($user, (string) $request->query('hash')) instanceof User;

        return $this->page($request, $waiting ? 'ready' : ApplyEmailChange::EXPIRED);
    }

    public function confirm(Request $request, int $user, ApplyEmailChange $applyEmailChange): Response
    {
        return $this->page($request, $request->hasValidSignature(absolute: false)
            ? $applyEmailChange->handle($user, (string) $request->query('hash'))
            : ApplyEmailChange::EXPIRED);
    }

    public function destroy(Request $request, CancelEmailChange $cancelEmailChange): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $cancelEmailChange->handle($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('New address cancelled.')]);

        return to_route('profile.edit');
    }

    /** @param  'ready'|ApplyEmailChange::CHANGED|ApplyEmailChange::EXPIRED|ApplyEmailChange::TAKEN  $state */
    private function page(Request $request, string $state): Response
    {
        return Inertia::render('auth/email-change-open', [
            'state' => $state,
            // Its own path and query: never a host or scheme a proxy may have changed.
            'action' => $state === 'ready' ? $request->getRequestUri() : null,
        ]);
    }
}
