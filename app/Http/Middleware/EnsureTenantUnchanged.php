<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Inertia\Inertia;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;

/**
 * A tab left on another business never saves into the one worked in now
 * (CHW-147, design 10f): every Inertia visit names the tenant its page was
 * rendered for (HEADER, tenant-guard.ts), and a change sent from a page of
 * another one (switched since in another tab, or by a business email's link)
 * is refused before anything reads it, route bindings included: nothing is
 * saved, and the dashboard of the business worked in now says why.
 *
 * A save from a page of an older build (a tab open since before a deploy,
 * maybe from before the header) saves nothing either: the page it came from
 * is loaded again, as Inertia does for a visit of an older build (CHW-317).
 * Not a permission check (the user may work in both): a request that names
 * no tenant (a plain one, or from a page with none, such as settings) is
 * left to the route's own rules. A route that acts on no tenant's data (the
 * switch itself, signing out from an email's link) opts out where it is
 * defined (withoutMiddleware).
 */
final readonly class EnsureTenantUnchanged
{
    public const string HEADER = 'X-Punchcard-Tenant';

    /** The choice of a page rendered with no tenant (the dashboard asking where they work today). */
    public const string NONE = 'none';

    public function __construct(private TenantContext $context) {}

    /** The tenant a page is rendered for, as its visits name it. */
    public static function choiceOf(TenantContext $context): string
    {
        return match (true) {
            $context->businessId() !== null => SetTenant::businessChoice($context->businessId()),
            $context->organizationId() !== null => SetTenant::organizationChoice($context->organizationId()),
            default => self::NONE,
        };
    }

    /** The name of the tenant worked in: its business, or the organization across its network. */
    public static function nameOf(TenantContext $context): ?string
    {
        return $context->business()->name ?? $context->organization()?->name;
    }

    /** Whether a route's changes are checked (its pages name their tenant: HandleInertiaRequests). */
    public static function covers(Route $route): bool
    {
        return in_array(self::class, $route->gatherMiddleware(), true) && ! in_array(self::class, $route->excludedMiddleware(), true);
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        // Inertia checks the build on a page asked for, never on a save: here a save of an older build is refused too.
        // Its own version, not Inertia::getVersion(): this runs before HandleInertiaRequests sets that (priority).
        if ($request->inertia() && (string) $request->header(Header::VERSION, '') !== (string) app(HandleInertiaRequests::class)->version($request)) {
            return Inertia::location($this->pageSentFrom($request));
        }

        $shown = $request->headers->get(self::HEADER);

        if ($shown === null || $shown === self::choiceOf($this->context)) {
            return $next($request);
        }

        $name = self::nameOf($this->context);

        Inertia::flash('toast', ['type' => 'error', 'message' => $name === null
            ? __('Not saved. You switched business in another tab.')
            : __('Not saved. You switched to :business in another tab, so this page now shows :business.', ['business' => $name])]);

        return to_route('dashboard', status: 303);
    }

    /** The page a save came from, to load again: on this app alone, else the dashboard. */
    private function pageSentFrom(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');

        return str_starts_with($referer, $request->getSchemeAndHttpHost().'/') ? $referer : route('dashboard');
    }
}
