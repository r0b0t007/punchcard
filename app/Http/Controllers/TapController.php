<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Taps\ApplyTap;
use App\Actions\Taps\DescribeTap;
use App\Actions\Taps\ReceiveTap;
use App\Models\Tap;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The NFC tap endpoint (CHW-25, sun-nfc-verification skill).
 *
 * - GET /t (rate limited before anything is recorded): ReceiveTap, then
 *   ApplyTap at once for a signed-in customer. A signed-out customer's
 *   pending tap id goes into the session only, never a URL or a form, and
 *   sign-in returns to /t/claim. Either way it redirects to the result page,
 *   so reloading it never taps again.
 * - GET /t/{tap}: the result, for its customer or the session that made it;
 *   anyone else gets a 404, guessed id or not.
 * - GET /t/claim (signed in): applies the session's pending tap once.
 */
final class TapController extends Controller
{
    /** The session key for the signed-out tap waiting for sign-in. */
    private const string PENDING = 'taps.pending';

    /** The session key for the taps this session may view (its own refused or pending ones). */
    private const string VIEWABLE = 'taps.viewable';

    public function __construct(private readonly TenantContext $context) {}

    public function receive(Request $request, ReceiveTap $receiveTap, ApplyTap $applyTap): RedirectResponse
    {
        $user = $request->user();
        $tap = $receiveTap->handle($this->text($request, 'e'), $this->text($request, 'c'), $user, $request->ip(), $request->userAgent());

        if ($tap->isPending() && $user instanceof User) {
            $tap = $applyTap->handle($tap, $user);
        } elseif ($tap->isPending()) {
            $request->session()->put(self::PENDING, $tap->id);
            redirect()->setIntendedUrl(route('taps.claim'));
        }

        $this->allowViewing($request, $tap);

        return to_route('taps.show', $tap->id);
    }

    public function show(Request $request, int $tap, DescribeTap $describeTap): Response|RedirectResponse
    {
        $found = $this->context->bypass(fn (): ?Tap => Tap::query()->find($tap));
        $user = $request->user();
        $mine = $found instanceof Tap && (
            ($user instanceof User && $found->user_id === $user->id)
            || in_array($found->id, (array) $request->session()->get(self::VIEWABLE, []), true)
        );

        abort_unless($mine, 404);

        // Signed in since tapping signed out: claim it.
        if ($found->isPending() && $user instanceof User && $request->session()->get(self::PENDING) === $found->id) {
            return to_route('taps.claim');
        }

        $screen = $describeTap->handle($found);

        return Inertia::render($screen['component'], $screen['props']);
    }

    public function claim(Request $request, ApplyTap $applyTap): RedirectResponse
    {
        $id = $request->session()->pull(self::PENDING);
        $tap = is_int($id) ? $this->context->bypass(fn (): ?Tap => Tap::query()->find($id)) : null;

        if (! $tap instanceof Tap) {
            return to_route('home');
        }

        $applyTap->handle($tap, $request->user());

        return to_route('taps.show', $tap->id);
    }

    /** A query value as text: anything else (an array, nothing) is a malformed tap, never a type error. */
    private function text(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }

    /** The last few taps of this session, so a signed-out customer can see their own results. */
    private function allowViewing(Request $request, Tap $tap): void
    {
        $viewable = (array) $request->session()->get(self::VIEWABLE, []);

        $request->session()->put(self::VIEWABLE, array_slice([...$viewable, $tap->id], -10));
    }
}
