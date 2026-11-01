<?php

declare(strict_types=1);

use App\Actions\Admin\StartImpersonation;
use App\Enums\BusinessRole;
use App\Enums\DocumentKind;
use App\Enums\PlatformRole;
use App\Http\Middleware\EnsureTenantUnchanged;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTenant;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Support\Auth\MailedLink;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A tab left on another business (CHW-147, design 10f)
|--------------------------------------------------------------------------
|
| Every Inertia visit names the tenant its page was rendered for. Switched
| since in another tab (or by a business email's link), a change sent from
| that page saves nothing: the server refuses it before reading it, and the
| dashboard of the business worked in now says why.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->context->bypass(function (): void {
        $this->tenants->orgA->forceFill(['name' => 'Pâtisserie Amal'])->save();
        $this->tenants->a1->forceFill(['name' => 'Amal Gauthier'])->save();
        $this->tenants->a2->forceFill(['name' => 'Amal Maârif'])->save();
    });

    // Owns both shops; works in A1 now, while a tab still shows A2.
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $this->tenants->member($this->owner, $this->tenants->a2, BusinessRole::Owner);
    $this->onA1 = fn () => $this->actingAs($this->owner)->withSession([SetTenant::SESSION_KEY => SetTenant::businessChoice($this->tenants->a1->id)]);
    $this->shown = fn (string $choice): array => [EnsureTenantUnchanged::HEADER => $choice];
    $this->a2Page = fn (): array => ($this->shown)(SetTenant::businessChoice($this->tenants->a2->id));
    // An Inertia visit from a page of this build (or another), naming its tenant if it has one, as tenant-guard.ts sends it.
    $this->inertia = fn (?string $choice, ?string $build = null): array => [
        Header::INERTIA => 'true',
        Header::VERSION => $build ?? (string) app(HandleInertiaRequests::class)->version(request()),
        ...($choice === null ? [] : ($this->shown)($choice)),
    ];
    $this->invite = fn (array $headers) => ($this->onA1)()->post(route('staff.invitations.store'), ['email' => 'nadia@example.com'], $headers);
    $this->invitations = fn (): int => $this->context->bypass(fn (): int => StaffInvitation::query()->count());
});

it('refuses every change sent from a page of another business, before reading it', function (): void {
    $guarded = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route): bool => EnsureTenantUnchanged::covers($route))
        ->flatMap(fn (RouteDefinition $route): array => array_map(fn (string $method): array => [$method, $route], array_diff($route->methods(), ['GET', 'HEAD'])));

    // Every one the business app has: a new one is guarded too, or opts out where it is defined (the switch, signing
    // out from an email's link: they act on no tenant's data).
    expect($guarded->map(fn (array $pair): string => $pair[0].' '.$pair[1]->getName())->sort()->values()->all())->toBe([
        'DELETE counter.disarm', 'DELETE staff.destroy', 'DELETE staff.invitations.destroy',
        'PATCH cards.status', 'PATCH stampers.status',
        'POST business.qr-stand.printed', 'POST campaigns.store', 'POST cards.banner', 'POST cards.store', 'POST counter.arm', 'POST counter.scans.redeem',
        'POST counter.scans.stamp', 'POST counter.scans.store', 'POST staff.invitations.store', 'POST verification.store',
        'PUT cards.update',
    ]);

    foreach ($guarded as [$method, $route]) {
        // Any id: refused before a binding reads it (another business's {card} would be a bare 404).
        $parameters = array_map(fn (string $name): string => match ($name) {
            'kind' => DocumentKind::IdDocument->value,
            'scan' => (string) Str::ulid(),
            default => '1',
        }, array_combine($route->parameterNames(), $route->parameterNames()));

        $response = ($this->onA1)()->call($method, route($route->getName(), $parameters), server: ['HTTP_X_PUNCHCARD_TENANT' => SetTenant::businessChoice($this->tenants->a2->id)]);

        expect($response->headers->get('Location'))->toBe(route('dashboard'), $method.' '.$route->getName().' answered '.$response->getStatusCode());
        $response
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Not saved. You switched to Amal Gauthier in another tab, so this page now shows Amal Gauthier.']);
    }
});

it('saves nothing from a page of another business, and what its page shows saves', function (): void {
    ($this->invite)(($this->a2Page)())->assertStatus(303);
    expect(($this->invitations)())->toBe(0);

    ($this->invite)(($this->shown)(SetTenant::businessChoice($this->tenants->a1->id)))->assertSessionHasNoErrors();
    expect(($this->invitations)())->toBe(1);
});

it('leaves a plain request, not Inertia\'s, to the route\'s own rules', function (): void {
    // A build with a version (tests have none): a plain request names no build, and is never taken for an older one.
    config(['app.asset_url' => 'https://assets.punchcard.test']);

    ($this->invite)([])->assertSessionHasNoErrors();

    expect(($this->invitations)())->toBe(1);
});

it('saves nothing from a page of an older build, and loads that page again', function (?string $choice): void {
    // A tab open since before a deploy (CHW-317), maybe from before the header: Inertia's own reload, not a toast.
    ($this->invite)([...($this->inertia)($choice, 'an-older-build'), 'Referer' => route('staff.index')])
        ->assertStatus(409)
        ->assertHeader(Header::LOCATION, route('staff.index'));
    // A Referer elsewhere is never followed: the dashboard.
    ($this->invite)([...($this->inertia)($choice, 'an-older-build'), 'Referer' => 'https://elsewhere.example/business/staff'])
        ->assertHeader(Header::LOCATION, route('dashboard'));

    expect(($this->invitations)())->toBe(0);
})->with(['naming no tenant' => null, 'naming a tenant' => 'business:1']);

it('lets a page of this build that names no tenant save, as the route allows', function (): void {
    // A build with a version (tests have none), as in production: a page of it is never taken for an older one.
    config(['app.asset_url' => 'https://assets.punchcard.test']);

    // A page with no tenant (settings) is not a stale one: the route's own rules apply.
    ($this->invite)(($this->inertia)(null))->assertSessionHasNoErrors();
    expect(($this->invitations)())->toBe(1);

    // From a page that names it, the same.
    ($this->onA1)()->post(route('staff.invitations.store'), ['email' => 'salim@example.com'], ($this->inertia)(SetTenant::businessChoice($this->tenants->a1->id)))->assertSessionHasNoErrors();
    expect(($this->invitations)())->toBe(2);
});

it('lets the switch, and signing out from an email\'s link, through from a page of another business', function (): void {
    ($this->onA1)()->put(route('tenant.update'), ['choice' => SetTenant::businessChoice($this->tenants->a2->id)], ($this->a2Page)())
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(SetTenant::SESSION_KEY, SetTenant::businessChoice($this->tenants->a2->id));

    $link = MailedLink::toBusiness($this->tenants->a1->id, 'verification', User::factory()->create());
    ($this->onA1)()->post($link, [], ($this->a2Page)())->assertRedirect(route('business.login'));
    $this->assertGuest();
});

it('never refuses a page asked for, a poll of a page left behind included', function (): void {
    ($this->onA1)()->get(route('counter.show'), ($this->a2Page)())->assertOk();
});

it('lets staff save from their own counter', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $stamper = $this->tenants->stamper($this->tenants->a1);

    $this->actingAs($staff)->post(route('counter.arm', $stamper), ['qty' => 1], ($this->shown)(SetTenant::businessChoice($this->tenants->a1->id)))
        ->assertRedirect(route('counter.show'))
        ->assertInertiaFlashMissing('toast');
});

it('leaves an admin signed in as an owner to the impersonation guard, which refuses first', function (): void {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
    $this->actingAs($admin);
    app(StartImpersonation::class)->handle($admin, $this->tenants->a1, $this->owner, app('session')->driver());

    $this->post(route('staff.invitations.store'), ['email' => 'nadia@example.com'], ($this->a2Page)())
        ->assertInertiaFlash('toast.message', 'Not while you are signed in as the owner: nothing was changed.');
});

it('names the network, or no business, worked in now', function (): void {
    $this->tenants->admin($this->owner, $this->tenants->orgA);

    $this->actingAs($this->owner)->withSession([SetTenant::SESSION_KEY => SetTenant::organizationChoice($this->tenants->orgA->id)])
        ->post(route('staff.invitations.store'), [], ($this->a2Page)())
        ->assertInertiaFlash('toast.message', 'Not saved. You switched to Pâtisserie Amal in another tab, so this page now shows Pâtisserie Amal.');

    // In two organizations and none chosen: no tenant, no name.
    $this->tenants->member($this->owner, $this->tenants->b1, BusinessRole::Owner);
    $this->flushSession();
    $this->actingAs($this->owner)->post(route('staff.invitations.store'), [], ($this->a2Page)())
        ->assertInertiaFlash('toast.message', 'Not saved. You switched business in another tab.');
});

it('passes a page rendered with no business while none is chosen', function (): void {
    $this->tenants->member($this->owner, $this->tenants->b1, BusinessRole::Owner);

    $this->actingAs($this->owner)->withSession([])->post(route('staff.invitations.store'), [], ($this->shown)(EnsureTenantUnchanged::NONE))
        ->assertInertiaFlashMissing('toast');
});

it('tells each business page the tenant it is rendered for, and no other page', function (): void {
    // When rendered (the server's clock): the latest render is where the session works, for the app's other tabs.
    $this->freezeTime();
    ($this->onA1)()->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('tenant', ['choice' => SetTenant::businessChoice($this->tenants->a1->id), 'name' => 'Amal Gauthier', 'at' => now()->getTimestampMs()]));
    // Full screen, no sidebar: still named, for counter mode's saves.
    ($this->onA1)()->get(route('counter.show'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('tenant.choice', SetTenant::businessChoice($this->tenants->a1->id)));
    ($this->onA1)()->get(route('profile.edit'))->assertInertia(fn (Assert $page): Assert => $page->where('tenant', null));

    // Across the network: its own choice, its name.
    $this->tenants->admin($this->owner, $this->tenants->orgA);
    $this->actingAs($this->owner)->withSession([SetTenant::SESSION_KEY => SetTenant::organizationChoice($this->tenants->orgA->id)])->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('tenant.choice', SetTenant::organizationChoice($this->tenants->orgA->id))->where('tenant.name', 'Pâtisserie Amal'));

    $this->tenants->member($this->owner, $this->tenants->b1, BusinessRole::Owner);
    $this->flushSession();
    $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('tenant.choice', EnsureTenantUnchanged::NONE)->where('tenant.name', null));
});
