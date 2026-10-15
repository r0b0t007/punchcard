<?php

namespace App\Http\Middleware;

use App\Actions\Tenancy\HasMemberships;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Auth\PasskeyOffer;
use App\Support\Locales;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            // What the sidebar offers in the tenant being worked in: the cards page to whoever runs the program or the business (CHW-32).
            // A customer's settings pages use the customer layout and its tab bar (CHW-27); read there only.
            'customer' => fn (): bool => $request->is('settings/*') && $request->user() instanceof User
                && ! app(HasMemberships::class)->handle($request->user()),
            // Face ID or a fingerprint next time, offered after an email-link sign-in (CHW-23).
            'offerPasskey' => fn (): bool => $request->user() instanceof User && $request->hasSession()
                && PasskeyOffer::holds($request->session(), $request->user()),
            // The installable app the page belongs to, if any (UsePwa, CHW-35).
            'pwa' => null,
            'nav' => fn (): array => [
                'cards' => $request->user()?->can('viewAny', LoyaltyCard::class) ?? false,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'locale' => $locale,
            'dir' => Locales::direction($locale),
            'locales' => collect(Locales::names())
                ->map(fn (string $name, string $code): array => ['code' => $code, 'name' => $name])
                ->values()
                ->all(),
            // Sent once per locale and cached by the client; switching language changes the key.
            'translations' => Inertia::once(fn (): array => Locales::translations($locale))
                ->as('translations-'.$locale),
        ];
    }
}
