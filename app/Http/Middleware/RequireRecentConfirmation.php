<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth\RecentConfirmation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request that needs a confirmation this recent, else back to a page
 * that asks for one (RequirePassword sends to the confirm page instead,
 * then back to the URL: no good for a POST). Placed before a throttle, so
 * an expired attempt never counts against the limit.
 */
final class RequireRecentConfirmation
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $redirectToRoute, string $seconds): Response
    {
        if (! RecentConfirmation::holds($request->session(), (int) $seconds)) {
            return to_route($redirectToRoute);
        }

        return $next($request);
    }
}
