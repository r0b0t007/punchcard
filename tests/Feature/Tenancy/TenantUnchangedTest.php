<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\DocumentKind;
use App\Http\Middleware\EnsureTenantUnchanged;
use App\Http\Middleware\SetTenant;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
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
    $this->invite = fn (array $headers) => ($this->onA1)()->post(route('staff.invitations.store'), ['email' => 'nadia@example.com'], $headers);
    $this->invitations = fn (): int => $this->context->bypass(fn (): int => StaffInvitation::query()->count());
});

it('refuses every change sent from a page of another business, before reading it', function (): void {
    $guarded = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route): bool => in_array(EnsureTenantUnchanged::class, $route->gatherMiddleware(), true))
        ->flatMap(fn (RouteDefinition $route): array => array_map(fn (string $method): array => [$method, $route], array_diff($route->methods(), ['GET', 'HEAD'])))
        ->reject(fn (array $pair): bool => in_array($pair[1]->getName(), ['tenant.update', 'business.open.switch-account'], true));

    // Every one the business app has: a new one is guarded too, or this list says why not.
    expect($guarded->map(fn (array $pair): string => $pair[0].' '.$pair[1]->getName())->sort()->values()->all())->toBe([
        'DELETE counter.disarm', 'DELETE staff.destroy', 'DELETE staff.invitations.destroy',
        'PATCH cards.status', 'PATCH stampers.status',
        'POST business.qr-stand.printed', 'POST cards.banner', 'POST cards.store', 'POST counter.arm', 'POST counter.scans.redeem',
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

it('leaves a request without the header to the route\'s own rules', function (): void {
    ($this->invite)([])->assertSessionHasNoErrors();

    expect(($this->invitations)())->toBe(1);
});

it('lets the switch itself through from a page of another business', function (): void {
    ($this->onA1)()->put(route('tenant.update'), ['choice' => SetTenant::businessChoice($this->tenants->a2->id)], ($this->a2Page)())
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(SetTenant::SESSION_KEY, SetTenant::businessChoice($this->tenants->a2->id));
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
    ($this->onA1)()->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('tenant', ['choice' => SetTenant::businessChoice($this->tenants->a1->id), 'name' => 'Amal Gauthier']));
    // Full screen, no sidebar: still named, for counter mode's saves.
    ($this->onA1)()->get(route('counter.show'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('tenant.choice', SetTenant::businessChoice($this->tenants->a1->id)));
    ($this->onA1)()->get(route('profile.edit'))->assertInertia(fn (Assert $page): Assert => $page->where('tenant', null));

    // Across the network: its own choice, its name.
    $this->tenants->admin($this->owner, $this->tenants->orgA);
    $this->actingAs($this->owner)->withSession([SetTenant::SESSION_KEY => SetTenant::organizationChoice($this->tenants->orgA->id)])->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('tenant', ['choice' => SetTenant::organizationChoice($this->tenants->orgA->id), 'name' => 'Pâtisserie Amal']));

    $this->tenants->member($this->owner, $this->tenants->b1, BusinessRole::Owner);
    $this->flushSession();
    $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('tenant', ['choice' => EnsureTenantUnchanged::NONE, 'name' => null]));
});
