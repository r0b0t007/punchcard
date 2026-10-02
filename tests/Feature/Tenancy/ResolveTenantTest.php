<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Http\Middleware\SetTenant;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Resolving the current tenant from the signed-in user
|--------------------------------------------------------------------------
|
| Owners and staff work inside one business; an org admin works across the
| organization. A switcher choice in the session wins when valid; anything
| ambiguous resolves to no tenant (fail closed).
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();

    Route::middleware(['web', 'tenant'])->get('/_tenant', fn (TenantContext $context): array => [
        'organization' => $context->organizationId(),
        'business' => $context->businessId(),
    ]);

    Route::middleware(['web', 'auth', 'tenant'])->get('/_location/{location}', fn (Location $location): string => $location->name);
});

it('sets the business and its organization for an owner or staff member', function (BusinessRole $role): void {
    $user = $this->tenants->member(User::factory()->create(), $this->tenants->a1, $role);

    $this->actingAs($user)->get('/_tenant')
        ->assertExactJson(Tenants::context($this->tenants->orgA->id, $this->tenants->a1->id));
})->with([BusinessRole::Owner, BusinessRole::Staff]);

it('sets only the organization for an org admin', function (): void {
    $user = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);

    $this->actingAs($user)->get('/_tenant')->assertExactJson(Tenants::context($this->tenants->orgA->id));
});

it('sets no tenant for a customer or a guest', function (): void {
    $this->actingAs(User::factory()->create())->get('/_tenant')->assertExactJson(Tenants::context(null));

    auth()->logout();
    app(TenantContext::class)->set($this->tenants->orgA, $this->tenants->a1);

    $this->get('/_tenant')->assertExactJson(Tenants::context(null));
});

it('puts HQ that also runs one site in that site, and lets them switch to the organization', function (): void {
    $user = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $this->tenants->member($user, $this->tenants->a1, BusinessRole::Owner);

    $this->actingAs($user)->get('/_tenant')
        ->assertExactJson(Tenants::context($this->tenants->orgA->id, $this->tenants->a1->id));

    $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'org:'.$this->tenants->orgA->id])->get('/_tenant')
        ->assertExactJson(Tenants::context($this->tenants->orgA->id));
});

it('gives an org admin who is staff at two of its businesses the organization', function (): void {
    $user = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $this->tenants->member($user, $this->tenants->a1);
    $this->tenants->member($user, $this->tenants->a2);

    $this->actingAs($user)->get('/_tenant')->assertExactJson(Tenants::context($this->tenants->orgA->id));
});

it('needs a choice for memberships that do not resolve to one tenant', function (string $case): void {
    $user = User::factory()->create();

    match ($case) {
        'org admin of A and staff at B1' => [$this->tenants->admin($user, $this->tenants->orgA), $this->tenants->member($user, $this->tenants->b1)],
        'org admin of two organizations' => [$this->tenants->admin($user, $this->tenants->orgA), $this->tenants->admin($user, $this->tenants->orgB)],
        'staff at A1 and A2' => [$this->tenants->member($user, $this->tenants->a1), $this->tenants->member($user, $this->tenants->a2)],
        'staff at A1 and B1' => [$this->tenants->member($user, $this->tenants->a1), $this->tenants->member($user, $this->tenants->b1)],
    };

    $this->actingAs($user)->get('/_tenant')->assertExactJson(Tenants::context(null));
})->with(['org admin of A and staff at B1', 'org admin of two organizations', 'staff at A1 and A2', 'staff at A1 and B1']);

it('honours a valid switcher choice', function (string $choice, string $expected): void {
    $user = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $this->tenants->member($user, $this->tenants->b1);

    $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => str_replace(['{orgA}', '{b1}'], [$this->tenants->orgA->id, $this->tenants->b1->id], $choice)])->get('/_tenant')
        ->assertExactJson($expected === 'orgA' ? Tenants::context($this->tenants->orgA->id) : Tenants::context($this->tenants->orgB->id, $this->tenants->b1->id));
})->with([
    'organization A' => ['org:{orgA}', 'orgA'],
    'business B1' => ['business:{b1}', 'b1'],
]);

it('ignores a choice the user does not belong to, or that is malformed', function (string $choice): void {
    $user = $this->tenants->member(User::factory()->create(), $this->tenants->a1);

    $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => str_replace(['{orgB}', '{b1}'], [$this->tenants->orgB->id, $this->tenants->b1->id], $choice)])->get('/_tenant')
        ->assertExactJson(Tenants::context($this->tenants->orgA->id, $this->tenants->a1->id));
})->with(['business:{b1}', 'org:{orgB}', 'business:abc', '{b1}', 'org:-1']);

it('resolves route model bindings inside the tenant', function (): void {
    $user = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->actingAs($user);

    $this->get('/_location/'.$this->tenants->locationOf($this->tenants->a1)->id)->assertOk()->assertSee('A1 site');
    $this->get('/_location/'.$this->tenants->locationOf($this->tenants->a2)->id)->assertNotFound();
    $this->get('/_location/'.$this->tenants->locationOf($this->tenants->b1)->id)->assertNotFound();
});

it('falls back to normal resolution when the chosen membership was removed', function (): void {
    $user = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->tenants->member($user, $this->tenants->b1);
    app(TenantContext::class)->bypass(fn () => $this->tenants->b1->members()->detach([$user->id]));

    $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'business:'.$this->tenants->b1->id])->get('/_tenant')
        ->assertExactJson(Tenants::context($this->tenants->orgA->id, $this->tenants->a1->id));
});

it('ignores an org admin choosing a business of the organization they are not staff of', function (): void {
    $user = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);

    $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'business:'.$this->tenants->a2->id])->get('/_tenant')
        ->assertExactJson(Tenants::context($this->tenants->orgA->id));
});
