<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which installable app a page belongs to (CHW-35, pwa-conventions skill):
 * `customer` (My cards, rewards, taps, the join page) or `business`
 * (punchcard Pro: the dashboard, cards, onboarding). The page links that
 * app's manifest and registers the service worker; a page outside both
 * (the marketing site, sign-in, settings, admin) does neither.
 *
 * Shared as the Inertia prop `pwa`, which app.blade.php reads too.
 */
final class UsePwa
{
    /**
     * Each app's home-screen title, as its manifest's short_name.
     *
     * @var array<string, string>
     */
    public const array APPS = ['customer' => 'punchcard', 'business' => 'punchcard Pro'];

    public function handle(Request $request, Closure $next, string $app): Response
    {
        if (! array_key_exists($app, self::APPS)) {
            throw new InvalidArgumentException("Unknown app [{$app}].");
        }

        Inertia::share('pwa', ['app' => $app, 'title' => self::APPS[$app]]);

        return $next($request);
    }
}
