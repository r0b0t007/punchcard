<?php

namespace App\Providers;

use App\Actions\Taps\RekeyTapClaimToken;
use App\Enums\PlatformRole;
use App\Http\Middleware\PlatformAdminWorksAcrossTenants;
use App\Models\Business;
use App\Models\NfcTag;
use App\Models\User;
use App\Policies\Invariants;
use App\Support\Auth\ActiveUserProvider;
use App\Support\Http\ClientAddress;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\QueuedTenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerKeyDiversifier();
        $this->registerTenantContext();
    }

    /**
     * One TenantContext per request or job, empty until SetTenant (or the code
     * itself) sets a tenant. Tenant scopes and bypass() must share this instance.
     */
    private function registerTenantContext(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(QueuedTenant::class);
    }

    /**
     * Queued jobs run in the tenant they were dispatched from (QueuedTenant).
     * JobAttempted fires after every attempt, run or failed, on a worker and on
     * the sync queue, so the context around the job always comes back. The
     * payload hook is static on the base Queue: the facade would open the
     * default connection on every boot just to register it.
     */
    private function carryTenantIntoQueuedJobs(): void
    {
        Queue::createPayloadUsing(fn (): array => app(QueuedTenant::class)->payload());
        Event::listen(JobProcessing::class, [QueuedTenant::class, 'enter']);
        Event::listen(JobAttempted::class, [QueuedTenant::class, 'leave']);
    }

    /**
     * One KeyDiversifier per process, built from NFC_SUN_MASTER_KEY the first time a
     * tap is verified or a tag provisioned. Long-running workers keep it until they
     * restart, so restart them after changing the key. It is lazy so an app without
     * the key (CI, local UI work) still boots; `php artisan punchcard:nfc:check`
     * validates the configuration in the deploy script.
     */
    private function registerKeyDiversifier(): void
    {
        $this->app->singleton(function (): KeyDiversifier {
            $masterKey = config('punchcard.nfc.sun_master_key');

            return KeyDiversifier::fromHex(is_string($masterKey) ? $masterKey : null);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->nameAuditSubjects();
        $this->carryTenantIntoQueuedJobs();
        $this->registerUserProvider();
        $this->registerRateLimiters();
        $this->letAdminsThroughInFilament();
        $this->runAdminPanelUpdatesAcrossTenants();
        $this->rekeyTapClaimTokenAtSignIn();
        $this->trustConfiguredProxies();
    }

    /**
     * Livewire's update route, where the admin panel's table actions, filters
     * and modals run, with the same bypass as the panel's pages
     * (PlatformAdminWorksAcrossTenants, which only applies to the platform
     * admin and to components rendered on a panel page). Livewire keeps its
     * web middleware and header guard.
     */
    private function runAdminPanelUpdatesAcrossTenants(): void
    {
        Livewire::setUpdateRoute(fn (array $handle, string $path) => Route::post($path, $handle)
            ->middleware(['web', PlatformAdminWorksAcrossTenants::class]));
    }

    /**
     * A platform admin passes every ability, but only inside the Filament panel
     * (CHW-22): in the app itself they are a customer like anyone, with no
     * business or organization rights. Never past App\Policies\Invariants
     * (a stamp written or changed, a tag made or deleted, a held card deleted or
     * remodelled): there the answer is an explicit false, since Filament allows
     * an action whose policy has no method for it. null leaves every other
     * check to the policies.
     */
    private function letAdminsThroughInFilament(): void
    {
        /** @param  array<int, mixed>  $arguments */
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            if (! Filament::isServing() || Filament::getCurrentPanel()?->getId() !== 'admin' || ! $user->hasRole(PlatformRole::Admin->value)) {
                return null;
            }

            return ! Invariants::forbid($ability, $arguments);
        });
    }

    /**
     * The proxies in front of the app (Cloudflare's ranges in production,
     * TRUSTED_PROXIES), so request()->ip() is the customer's, for the tap rate
     * limit and the tap log. None by default: X-Forwarded-For from anyone else
     * is ignored, so it can't dodge the limit or fake the logged IP.
     */
    private function trustConfiguredProxies(): void
    {
        $proxies = config('punchcard.trusted_proxies');

        // "*" stays a string: Laravel only reads the bare string as "trust whatever proxy connects".
        if ($proxies === '*' || (is_array($proxies) && $proxies !== [])) {
            TrustProxies::at($proxies);
        }
    }

    /**
     * A new tap claim token at sign-in (Login fires once the session id is
     * regenerated), so a session id planted before sign-in can't claim the
     * taps made later in that browser (TapSession, CHW-142).
     */
    private function rekeyTapClaimTokenAtSignIn(): void
    {
        Event::listen(Login::class, function (): void {
            if (request()->hasSession()) {
                app(RekeyTapClaimToken::class)->handle(request()->session());
            }
        });
    }

    /**
     * The tap endpoint (/t): per client address (ClientAddress) and per signed-in
     * customer, before the tap is received, since every request writes a tap row
     * (sun-nfc-verification skill). The reward screens (CHW-26): the redeem
     * screen's polling, and opening or closing redeem windows.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('tap', function (Request $request): array {
            // Over the limit: to the friendly "too many taps" page, rendered after the locale
            // and shared props are set (the throttle runs before them), with e/c out of the URL.
            $busy = fn (Request $request, array $headers): SymfonyResponse => to_route('taps.busy')->withHeaders($headers);

            $limits = [Limit::perMinute((int) config('punchcard.taps.per_ip_per_minute'))->by(ClientAddress::rateLimitKey($request->ip()))->response($busy)];

            if ($request->user() !== null) {
                $limits[] = Limit::perMinute((int) config('punchcard.taps.per_user_per_minute'))->by('user:'.$request->user()->getAuthIdentifier())->response($busy);
            }

            return $limits;
        });

        // Business sign-up (CHW-31): each one creates an organization, so a few an hour per client
        // address. Customers register through Fortify's route, which this never limits.
        RateLimiter::for('business-signup', fn (Request $request): Limit => Limit::perHour(10)->by(ClientAddress::rateLimitKey($request->ip())));

        // The redeem screen polls every 2 s (C4, CHW-26): its own limit per customer and reward, so
        // the polling never uses up another route's (a bare throttle:N,M shares one per-user counter).
        RateLimiter::for('reward-screen', fn (Request $request): Limit => Limit::perMinute(90)
            ->by('reward-screen:'.$request->user()?->getAuthIdentifier().':'.$request->route('reward')));
        RateLimiter::for('reward-window', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('reward-window:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * The "active-eloquent" user provider (config/auth.php): never authenticates
     * an anonymised account (ActiveUserProvider, CHW-139).
     */
    private function registerUserProvider(): void
    {
        Auth::provider('active-eloquent', fn (Application $app, array $config): ActiveUserProvider => new ActiveUserProvider($app->make(Hasher::class), $config['model']));
    }

    /**
     * Stable names for what the audit log points at (CHW-34), so a renamed
     * class never orphans its history. Not enforced: other morphs (spatie's
     * role holders) keep their class names.
     */
    private function nameAuditSubjects(): void
    {
        Relation::morphMap([
            'business' => Business::class,
            'nfc_tag' => NfcTag::class,
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
