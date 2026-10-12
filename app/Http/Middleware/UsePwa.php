<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which installable app a page belongs to (CHW-35, pwa-conventions skill):
 * `customer` (My cards, rewards, taps, the join page) or `business`
 * (punchcard Pro: the dashboard, cards, onboarding). The page links that
 * app's manifest and registers the service worker; a page outside both
 * (the marketing site, sign-in, settings, admin) does neither.
 */
final class UsePwa
{
    public const array APPS = ['customer', 'business'];

    public function handle(Request $request, Closure $next, string $app): Response
    {
        if (! in_array($app, self::APPS, true)) {
            throw new InvalidArgumentException("Unknown app [{$app}].");
        }

        View::share('pwa', $app);
        Inertia::share('pwa', $app);

        return $next($request);
    }
}
