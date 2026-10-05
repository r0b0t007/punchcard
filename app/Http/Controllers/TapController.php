<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Taps\ClaimPendingTaps;
use App\Actions\Taps\DescribeTap;
use App\Actions\Taps\TakeTap;
use App\Http\Requests\ReceiveTapRequest;
use App\Models\Tap;
use App\Models\User;
use App\Support\Taps\TapSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The NFC tap endpoint (CHW-25, sun-nfc-verification skill). Tap ids live in
 * the session only (TapSession), never in a URL, a form or a redirect; every
 * response is no-store (NeverCache).
 *
 * - GET /t (rate limited before anything is recorded): TakeTap, then the
 *   result page, so reloading never taps again. A tap still pending waits
 *   for sign-in, which returns to /t/claim.
 * - GET /t/result: this session's last tap (DescribeTap); once the customer
 *   is signed in, any tap still pending in the session is claimed first.
 * - GET /t/claim (signed in): ClaimPendingTaps.
 */
final class TapController extends Controller
{
    public function receive(ReceiveTapRequest $request, TapSession $session, TakeTap $takeTap): RedirectResponse
    {
        $tap = $takeTap->handle($request->sun('e'), $request->sun('c'), $request->user(), $request->ip(), $request->userAgent(), $session);

        if ($tap->isPending()) {
            redirect()->setIntendedUrl(route('taps.claim'));
        }

        return to_route('taps.result');
    }

    public function show(Request $request, TapSession $session, DescribeTap $describeTap): Response|RedirectResponse
    {
        $tap = $session->shown();

        if (! $tap instanceof Tap) {
            return to_route('home');
        }

        if ($request->user() instanceof User && $session->pending() !== []) {
            return to_route('taps.claim');
        }

        $screen = $describeTap->handle($tap);

        return Inertia::render($screen['component'], $screen['props']);
    }

    public function claim(Request $request, TapSession $session, ClaimPendingTaps $claimPendingTaps): RedirectResponse
    {
        return $claimPendingTaps->handle($session, $request->user()) instanceof Tap
            ? to_route('taps.result')
            : to_route('home');
    }
}
