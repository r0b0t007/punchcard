<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Taps\ApplyTap;
use App\Actions\Taps\DescribeTap;
use App\Actions\Taps\ReceiveTap;
use App\Enums\TapStatus;
use App\Models\Tap;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * The NFC tap endpoint (CHW-25, sun-nfc-verification skill). Tap ids live in
 * the session only, never in a URL, a form or a redirect.
 *
 * - GET /t (rate limited before anything is recorded): ReceiveTap, then
 *   ApplyTap at once for a signed-in customer. A signed-out customer's
 *   pending taps are kept in the session (a second tap does not drop the
 *   first, which may carry armed stamps), and sign-in returns to /t/claim.
 *   It redirects to /t/result, so reloading never taps again.
 * - GET /t/result: this session's last tap.
 * - GET /t/claim (signed in): applies the session's pending taps once, oldest
 *   first, each at its own time (a second one is usually refused for the
 *   cooldown), and shows the one that stamped.
 */
final class TapController extends Controller
{
    /** The session key for the signed-out taps waiting for sign-in. */
    private const string PENDING = 'taps.pending';

    /** The session key for the tap /t/result shows. */
    private const string LAST = 'taps.last';

    /** A signed-out customer's pending taps kept at most. */
    private const int MAX_PENDING = 5;

    public function __construct(private readonly TenantContext $context) {}

    public function receive(Request $request, ReceiveTap $receiveTap, ApplyTap $applyTap): RedirectResponse
    {
        $user = $request->user();
        $tap = $receiveTap->handle($this->text($request, 'e'), $this->text($request, 'c'), $user, $request->ip(), $request->userAgent());

        if ($tap->isPending() && $user instanceof User) {
            $tap = $applyTap->handle($tap, $user);
        } elseif ($tap->isPending()) {
            $this->keepPending($request, $tap);
            redirect()->setIntendedUrl(route('taps.claim'));
        }

        $request->session()->put(self::LAST, $tap->id);

        // The route's cache.headers only reaches 2xx responses.
        return to_route('taps.result')->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, DescribeTap $describeTap): Response|RedirectResponse
    {
        $tap = $this->find($request->session()->get(self::LAST));

        if (! $tap instanceof Tap) {
            return to_route('home');
        }

        // Signed in since tapping signed out (or a stamp that failed to apply): claim it now.
        if ($tap->isPending() && $request->user() instanceof User) {
            $this->keepPending($request, $tap);

            return to_route('taps.claim');
        }

        $screen = $describeTap->handle($tap);

        return Inertia::render($screen['component'], $screen['props']);
    }

    public function claim(Request $request, ApplyTap $applyTap): RedirectResponse
    {
        $ids = array_filter((array) $request->session()->pull(self::PENDING, []), is_int(...));
        sort($ids);
        $shown = null;

        foreach ($ids as $id) {
            $tap = $this->find($id);

            if (! $tap instanceof Tap) {
                continue;
            }

            try {
                $tap = $applyTap->handle($tap, $request->user());
            } catch (LogicException) {
                continue; // received or claimed by another customer: never theirs to show
            }

            if (! $shown instanceof Tap || $tap->status === TapStatus::Stamped) {
                $shown = $tap;
            }
        }

        if (! $shown instanceof Tap) {
            return to_route('home');
        }

        $request->session()->put(self::LAST, $shown->id);

        return to_route('taps.result');
    }

    private function find(mixed $id): ?Tap
    {
        return is_int($id) ? $this->context->bypass(fn (): ?Tap => Tap::query()->find($id)) : null;
    }

    private function keepPending(Request $request, Tap $tap): void
    {
        $pending = array_filter((array) $request->session()->get(self::PENDING, []), fn (mixed $id): bool => $id !== $tap->id);

        $request->session()->put(self::PENDING, array_slice([...$pending, $tap->id], -self::MAX_PENDING));
    }

    /** A query value as text: anything else (an array, nothing) is a malformed tap, never a type error. */
    private function text(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }
}
