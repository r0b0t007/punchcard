<?php

declare(strict_types=1);

use App\Http\Middleware\GiveSessionTapToken;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NeverCache;
use App\Http\Middleware\ResolveOnboardingBusiness;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            GiveSessionTapToken::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // A changed password (or a deleted, anonymised account) signs out every other device.
        $middleware->authenticateSessions();

        $middleware->alias(['tenant' => SetTenant::class, 'onboarding' => ResolveOnboardingBusiness::class]);

        // The tenant must be set before route model bindings resolve, so another
        // tenant's {location} or {business} is a 404 instead of an unscoped lookup.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetTenant::class);

        // NeverCache wraps the tap routes' auth and throttle, so a login redirect or a 429 is no-store too.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: NeverCache::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
