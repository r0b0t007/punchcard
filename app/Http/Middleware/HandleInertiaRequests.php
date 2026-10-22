<?php

namespace App\Http\Middleware;

use App\Actions\Tenancy\HasMemberships;
use App\Models\Business;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Auth\Impersonation;
use App\Support\Auth\PasskeyOffer;
use App\Support\Locales;
use App\Support\Tenancy\TenantContext;
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
            // The platform admin signed in as an owner (CHW-144): whom, where, until when, for the banner.
            'impersonating' => fn (): ?array => $this->impersonating($request),
            // The installable app the page belongs to, if any (UsePwa, CHW-35).
            'pwa' => null,
            'nav' => fn (): array => [
                'cards' => $request->user()?->can('viewAny', LoyaltyCard::class) ?? false,
                // Counter mode (CHW-29): whoever works in the business worked in.
                'counter' => $this->worksInBusiness($request),
                // The staff page (CHW-146): whoever runs the business worked in.
                'staff' => $this->managesStaff($request),
                // The stampers page (CHW-36, B12): the same people (StamperPolicy::viewAny).
                'stampers' => $this->managesStaff($request),
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

    /** @return array{owner: string, business: string, endsAt: string}|null */
    private function impersonating(Request $request): ?array
    {
        $impersonation = $request->hasSession() ? Impersonation::of($request->session()) : null;

        if (! $impersonation instanceof Impersonation || $request->user()?->getAuthIdentifier() !== $impersonation->ownerId) {
            return null;
        }

        $business = app(TenantContext::class)->bypass(fn (): ?string => Business::query()->whereKey($impersonation->businessId)->value('name'));

        return ['owner' => (string) $request->user()->name, 'business' => (string) $business, 'endsAt' => $impersonation->endsAt->toIso8601String()];
    }

    /** Whether the user works in a business (owner, staff or HQ), so has a counter there; no business, no counter. */
    private function worksInBusiness(Request $request): bool
    {
        return $request->user() instanceof User && app(TenantContext::class)->businessId() !== null;
    }

    /**
     * Whether the user runs the business worked in, so manages its staff; no business, no staff page. The same
     * rule as BusinessMemberPolicy::viewAny (owner or org admin there), read from the tenant so no page pays a
     * query: change one, change the other.
     */
    private function managesStaff(Request $request): bool
    {
        $context = app(TenantContext::class);

        return $request->user() instanceof User && $context->businessId() !== null && $context->canManageMembers();
    }
}
