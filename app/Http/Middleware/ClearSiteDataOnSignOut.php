<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Once a sign-out (or an account's deletion) has signed the user out, tells
 * the browser to drop everything the site kept (CHW-35): the HTTP cache,
 * the service worker's caches and the offline cards in IndexedDB. A shared
 * phone keeps nobody's cards. A deletion refused (a wrong password) keeps
 * them, as the user is still signed in.
 */
final class ClearSiteDataOnSignOut
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->routeIs('logout', 'profile.destroy') && Auth::guest()) {
            $response->headers->set('Clear-Site-Data', '"cache", "storage"');
        }

        return $response;
    }
}
