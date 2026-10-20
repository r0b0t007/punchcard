<?php

declare(strict_types=1);

use App\Http\Middleware\GiveSessionTapToken;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NeverCache;
use App\Http\Middleware\RecordLastActive;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Http\Middleware\ResolveOnboardingBusiness;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenant;
use App\Http\Middleware\UsePwa;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // The offline page stays up, so a new service worker (which precaches it) installs during maintenance too.
        $middleware->preventRequestsDuringMaintenance(except: ['offline']);

        $middleware->web(append: [
            HandleAppearance::class,
            GiveSessionTapToken::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // When an account was last used, for the inactivity rule (CHW-58): signed-in requests, signing in too.
            RecordLastActive::class,
        ]);

        // A changed password (or a deleted, anonymised account) signs out every other device.
        $middleware->authenticateSessions();

        $middleware->alias(['tenant' => SetTenant::class, 'onboarding' => ResolveOnboardingBusiness::class, 'pwa' => UsePwa::class]);

        // A signed-in user on a guest page (login, register) goes home, as after signing in (StartController, CHW-27).
        $middleware->redirectUsersTo('/start');

        // A guest of punchcard Pro signs in inside its scope (CHW-151), everyone else on the usual page.
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->is('business', 'business/*') ? route('business.login') : route('login'));

        // The tenant must be set before route model bindings resolve, so another
        // tenant's {location} or {business} is a 404 instead of an unscoped lookup.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetTenant::class);

        // NeverCache wraps the tap routes' auth and throttle, so a login redirect or a 429 is no-store too.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: NeverCache::class);

        // A stale confirmation goes back before the throttle counts it (Download my data, CHW-58).
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: RequireRecentConfirmation::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
        // Download my data (CHW-58) is a plain form, not an Inertia visit: an expired token goes back to the page, which
        // carries a fresh one, instead of a 419 page the installed app has no way back from.
        $exceptions->respond(fn (Response $response, Throwable $exception, Request $request): Response => $response->getStatusCode() === 419 && $request->routeIs('privacy.export')
            ? to_route('privacy.edit')
            : $response);
    })->create();
