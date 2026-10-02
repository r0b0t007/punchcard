<?php

namespace App\Providers;

use App\Support\Nfc\KeyDiversifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
