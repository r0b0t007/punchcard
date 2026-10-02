<?php

namespace App\Providers;

use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\QueuedTenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
