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
 * Shared as the Inertia prop `pwa`, with the <head> tags of the app:
 * app.blade.php writes them on a full load, PwaHead on every visit.
 */
final class UsePwa
{
    /**
     * Each app's home-screen title, as its manifest's short_name.
     *
     * @var array<string, string>
     */
    public const array APPS = ['customer' => 'punchcard', 'business' => 'punchcard Pro'];

    /** The browser chrome's colour, as both manifests' theme_color (espresso). */
    public const string THEME_COLOR = '#3B2A20';

    public function handle(Request $request, Closure $next, string $app): Response
    {
        if (! array_key_exists($app, self::APPS)) {
            throw new InvalidArgumentException("Unknown app [{$app}].");
        }

        Inertia::share('pwa', self::describe($app));

        return $next($request);
    }

    /**
     * The app and its <head> tags: the manifest link and the metas, by name.
     *
     * @return array{app: string, manifest: string, meta: array<string, string>}
     */
    public static function describe(string $app): array
    {
        return [
            'app' => $app,
            'manifest' => "/manifest-{$app}.webmanifest",
            'meta' => [
                'theme-color' => self::THEME_COLOR,
                'mobile-web-app-capable' => 'yes',
                'apple-mobile-web-app-capable' => 'yes',
                'apple-mobile-web-app-status-bar-style' => 'default',
                'apple-mobile-web-app-title' => self::APPS[$app],
            ],
        ];
    }
}
