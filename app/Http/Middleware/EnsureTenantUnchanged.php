<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A tab left on another business never saves into the one worked in now
 * (CHW-147, design 10f): every Inertia visit names the tenant its page was
 * rendered for (HEADER, tenant-guard.ts), and a change sent from a page of
 * another one (switched since in another tab, or by a business email's link)
 * is refused before anything reads it, route bindings included: nothing is
 * saved, and the dashboard of the business worked in now says why.
 *
 * Not a permission check (the user may work in both): a request without the
 * header (no Inertia page sent it) is left to the route's own rules. The
 * switch itself, and signing out from an email's link, act on no tenant's
 * data and pass.
 */
final readonly class EnsureTenantUnchanged
{
    public const string HEADER = 'X-Punchcard-Tenant';

    /** The choice of a page rendered with no tenant (the dashboard asking where they work today). */
    public const string NONE = 'none';

    private const array PASSES = ['tenant.update', 'business.open.switch-account'];

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

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! $request->headers->has(self::HEADER) || $request->routeIs(...self::PASSES)
            || $request->headers->get(self::HEADER) === self::choiceOf($this->context)) {
            return $next($request);
        }

        $name = $this->context->business()->name ?? $this->context->organization()?->name;

        Inertia::flash('toast', ['type' => 'error', 'message' => $name === null
            ? __('Not saved. You switched business in another tab.')
            : __('Not saved. You switched to :business in another tab, so this page now shows :business.', ['business' => $name])]);

        return to_route('dashboard', status: 303);
    }
}
