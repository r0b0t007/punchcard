<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Taps\ApplyTap;
use App\Actions\Taps\ClaimPendingTaps;
use App\Actions\Taps\DescribeTap;
use App\Actions\Taps\ReceiveTap;
use App\Http\Requests\ReceiveTapRequest;
use App\Models\Tap;
use App\Models\User;
use App\Support\Taps\TapSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The NFC tap endpoint (CHW-25, sun-nfc-verification skill). Tap ids live in
 * the session only (TapSession), never in a URL, a form or a redirect.
 *
 * - GET /t (rate limited before anything is recorded): ReceiveTap, then
 *   ApplyTap at once for a signed-in customer. A signed-out customer's tap
 *   waits in the session and sign-in returns to /t/claim. It redirects to
 *   /t/result, so reloading never taps again.
 * - GET /t/result: this session's last tap (DescribeTap).
 * - GET /t/claim (signed in): ClaimPendingTaps.
 */
final class TapController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function receive(ReceiveTapRequest $request, TapSession $session, ReceiveTap $receiveTap, ApplyTap $applyTap): RedirectResponse
    {
        $user = $request->user();
        $tap = $receiveTap->handle($request->sun('e'), $request->sun('c'), $user, $request->ip(), $request->userAgent());

        if ($tap->isPending() && $user instanceof User) {
            $tap = $applyTap->handle($tap, $user);
        } elseif ($tap->isPending()) {
            $session->keepPending($tap);
            redirect()->setIntendedUrl(route('taps.claim'));
        }

        $session->show($tap);

        // The route's cache.headers only reaches 2xx responses.
        return to_route('taps.result')->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, TapSession $session, DescribeTap $describeTap): Response|RedirectResponse
    {
        $id = $session->shown();
        $tap = $id === null ? null : $this->context->bypass(fn (): ?Tap => Tap::query()->find($id));

        if (! $tap instanceof Tap) {
            return to_route('home');
        }

        // Signed in since tapping signed out (or a stamp that failed to apply): claim it now.
        if ($tap->isPending() && $request->user() instanceof User) {
            $session->keepPending($tap);

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
