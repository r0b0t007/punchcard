<?php

namespace App\Providers;

use App\Support\Auth\ActiveUserProvider;
use App\Support\Http\ClientAddress;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\QueuedTenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
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
        $this->carryTenantIntoQueuedJobs();
        $this->registerUserProvider();
        $this->registerRateLimiters();
        $this->trustConfiguredProxies();
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
     * The tap endpoint (/t): per client address (ClientAddress) and per signed-in
     * customer, before the tap is received, since every request writes a tap row
     * (sun-nfc-verification skill).
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
