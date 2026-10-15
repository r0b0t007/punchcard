<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\ApplyEmailChange;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The link mailed to a new address asked for in settings (CHW-23,
 * RequestEmailChange), opened from that inbox, signed in or not:
 *
 * - GET /email/confirm/{user}: its page, one button (opening it spends
 *   nothing), or "expired" (out of date, used, or replaced by a later
 *   request).
 * - POST /email/confirm/{user}: the button (ApplyEmailChange): the account
 *   has that address from now on, unless another account took it meanwhile.
 */
final class EmailChangeController extends Controller
{
    public function show(Request $request, int $user): Response
    {
        $account = $request->hasValidSignature(absolute: false) ? User::query()->find($user) : null;
        $pending = $account instanceof User && ! $account->isAnonymised() ? $account->pendingEmail() : null;
        $waiting = is_string($pending) && hash_equals(sha1($pending), (string) $request->query('hash'));

        return $this->page($request, expired: ! $waiting);
    }

    public function confirm(Request $request, int $user, ApplyEmailChange $applyEmailChange): Response
    {
        $outcome = $request->hasValidSignature(absolute: false)
            ? $applyEmailChange->handle($user, (string) $request->query('hash'))
            : ApplyEmailChange::EXPIRED;

        return $this->page($request, expired: $outcome === ApplyEmailChange::EXPIRED, outcome: $outcome);
    }

    private function page(Request $request, bool $expired, ?string $outcome = null): Response
    {
        return Inertia::render('auth/email-change-open', [
            'expired' => $expired,
            'changed' => $outcome === ApplyEmailChange::CHANGED,
            'reason' => $outcome === ApplyEmailChange::TAKEN ? 'taken' : null,
            // Its own path and query: never a host or scheme a proxy may have changed.
            'action' => $expired || $outcome !== null ? null : $request->getRequestUri(),
        ]);
    }
}
