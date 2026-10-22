<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Admin\StopImpersonation;
use App\Models\User;
use App\Support\Auth\Impersonation;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the platform admin is signed in as an owner (CHW-144): their time
 * limit, the owner's account going, or the admin losing the panel ends it
 * on the next request; signing out goes back to admin instead; and only the
 * business app's pages may be read (Impersonation::allows). Every change,
 * the owner's own account, customer side and taps, and the admin panel are
 * refused with a toast, before anything runs. A page refused again before
 * any page showed (the business app itself sends there) goes back to admin,
 * never round in circles. Runs in the web group and the admin panel.
 */
final readonly class WhileImpersonating
{
    /** Set by a refused page, cleared once a page shows: a second refusal in between is a loop. */
    private const string REFUSED = 'impersonation.refused';

    public function __construct(private StopImpersonation $stopImpersonation) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $impersonation = $request->hasSession() ? Impersonation::of($request->session()) : null;

        if (! $impersonation instanceof Impersonation) {
            return $next($request);
        }

        $why = match (true) {
            $impersonation->hasEnded(now()) => StopImpersonation::EXPIRED,
            // The owner's account went, or signed in elsewhere as someone else: nobody left to be.
            $request->user()?->getAuthIdentifier() !== $impersonation->ownerId => StopImpersonation::OWNER_GONE,
            ! $this->adminStillMay($impersonation) => StopImpersonation::ADMIN_GONE,
            $request->routeIs('logout') => StopImpersonation::SIGNED_OUT,
            default => null,
        };

        if ($why !== null) {
            return $this->end($request, $impersonation, $why);
        }

        if (Impersonation::allows($request->route(), $request->method())) {
            $response = $next($request);

            if ($response->isSuccessful()) {
                $request->session()->forget(self::REFUSED);
            }

            return $response;
        }

        return $this->refuse($request, $impersonation);
    }

    /** The business's page in the admin panel: a full page load, Inertia request or not. */
    public static function backToAdmin(Request $request, int $businessId): Response
    {
        $url = route('filament.admin.resources.businesses.view', $businessId);

        return $request->header('X-Inertia') !== null ? Inertia::location($url) : redirect($url);
    }

    private function end(Request $request, Impersonation $impersonation, string $why): Response
    {
        $this->stopImpersonation->handle($request->session(), $why);
        $request->session()->forget(self::REFUSED);

        // Said on the admin's page; asking to go back, or signing out, needs no word.
        match ($why) {
            StopImpersonation::EXPIRED => $this->notify(__('Your time as the owner ran out: you are back in admin.')),
            StopImpersonation::OWNER_GONE => $this->notify(__('The owner\'s account is no longer available: you are back in admin.')),
            StopImpersonation::BACK => $this->notify(__('The owner\'s app sent you to a page you can\'t read as them: you are back in admin.')),
            default => null,
        };

        return Auth::check() ? self::backToAdmin($request, $impersonation->businessId) : redirect()->route('login');
    }

    private function notify(string $message): void
    {
        Notification::make()->warning()->title($message)->send();
    }

    /** Whether the admin behind it may still use the panel: an account that went, or lost the role, ends it. */
    private function adminStillMay(Impersonation $impersonation): bool
    {
        $admin = Auth::guard('web')->getProvider()->retrieveById($impersonation->adminId);

        return $admin instanceof User && $admin->canAccessPanel(Filament::getPanel('admin'));
    }

    private function refuse(Request $request, Impersonation $impersonation): Response
    {
        if ($request->isMethodSafe() && $request->session()->get(self::REFUSED) === true) {
            return $this->end($request, $impersonation, StopImpersonation::BACK);
        }

        // A link prefetched on hover is not something the admin did: no toast for it.
        if ($request->header('Purpose') !== 'prefetch') {
            Inertia::flash('toast', ['type' => 'error', 'message' => $request->is('admin', 'admin/*')
                ? __('Go back to admin first.')
                : __('Not while you are signed in as the owner: nothing was changed.')]);
        }

        if (! $request->isMethodSafe()) {
            return back();
        }

        $request->session()->put(self::REFUSED, true);

        return to_route('dashboard');
    }
}
