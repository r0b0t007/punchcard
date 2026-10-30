<?php

declare(strict_types=1);

use App\Actions\Admin\StartImpersonation;
use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\PlatformRole;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Switch business (CHW-147, design 10a and 10d)
|--------------------------------------------------------------------------
|
| Whoever works in more than one business picks the one the app works in:
| the sidebar's header names it and lists the others, by organization, and
| a network its user runs. Only what ResolveTenant would honour is offered
| or taken; anything else is a 404 and switches nothing. With no business
| chosen, the dashboard asks where they work today.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->context->bypass(function (): void {
        $this->tenants->orgA->forceFill(['name' => 'Pâtisserie Amal'])->save();
        $this->tenants->orgB->forceFill(['name' => 'Café Hafa'])->save();
        $this->tenants->b1->forceFill(['name' => 'Café Hafa'])->save();
        $this->tenants->a1->forceFill(['name' => 'Amal Gauthier'])->save();
        $this->tenants->a2->forceFill(['name' => 'Amal Maârif'])->save();
    });

    // Salma: owns Café Hafa (its own organization), runs the Pâtisserie Amal network, owns Gauthier, works at Maârif.
    $this->salma = User::factory()->create(['name' => 'Salma Bennani']);
    $this->tenants->member($this->salma, $this->tenants->b1, BusinessRole::Owner);
    $this->tenants->admin($this->salma, $this->tenants->orgB);
    $this->tenants->admin($this->salma, $this->tenants->orgA);
    $this->tenants->member($this->salma, $this->tenants->a1, BusinessRole::Owner);
    $this->tenants->member($this->salma, $this->tenants->a2, BusinessRole::Staff);

    $this->entry = fn (string $choice, string $name, string $role, bool $current = false): array => [
        'choice' => $choice, 'name' => $name, 'role' => $role, 'logoUrl' => null, 'initial' => mb_substr($name, 0, 1), 'current' => $current,
    ];
    $this->on = fn (User $user, ?string $choice) => $this->actingAs($user)->withSession($choice === null ? [] : [SetTenant::SESSION_KEY => $choice]);
});

it('lists every business and network the user may work in, by organization, the current one marked', function (): void {
    ($this->on)($this->salma, 'business:'.$this->tenants->b1->id)->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('tenants.current', ($this->entry)('business:'.$this->tenants->b1->id, 'Café Hafa', 'Owner', true))
        ->where('tenants.groups', [
            // An independent café's owner runs its organization too, but there the business is the account: no network entry.
            ['organization' => 'Café Hafa', 'entries' => [($this->entry)('business:'.$this->tenants->b1->id, 'Café Hafa', 'Owner', true)]],
            ['organization' => 'Pâtisserie Amal', 'entries' => [
                ($this->entry)('org:'.$this->tenants->orgA->id, 'Whole network', 'Runs the network'),
                ($this->entry)('business:'.$this->tenants->a1->id, 'Amal Gauthier', 'Owner'),
                ($this->entry)('business:'.$this->tenants->a2->id, 'Amal Maârif', 'Staff'),
            ]],
        ]));
});

it('leaves out what it would not honour: a business suspended or archived, a membership removed', function (): void {
    $this->context->bypass(function (): void {
        $this->tenants->a1->forceFill(['status' => BusinessStatus::Suspended])->save();
        $this->tenants->b1->forceFill(['archived_at' => now()])->save();
        BusinessMember::query()->where('user_id', $this->salma->id)->where('business_id', $this->tenants->a2->id)->delete();
    });

    ($this->on)($this->salma, 'org:'.$this->tenants->orgA->id)->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('tenants.current', ($this->entry)('org:'.$this->tenants->orgA->id, 'Whole network', 'Runs the network', true))
        ->where('tenants.groups', [['organization' => 'Pâtisserie Amal', 'entries' => [
            ($this->entry)('org:'.$this->tenants->orgA->id, 'Whole network', 'Runs the network', true),
        ]]]));
});

it('names the one business of someone who works in one, and nothing for a customer', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a2);

    ($this->on)($staff, null)->get(route('counter.show'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('tenants.current', ($this->entry)('business:'.$this->tenants->a2->id, 'Amal Maârif', 'Staff', true))
        ->count('tenants.groups', 1));

    ($this->on)(User::factory()->create(), null)->get(route('app.cards'))->assertInertia(fn (Assert $page): Assert => $page->where('tenants', null));
    // Outside the business app and settings, nobody pays its queries.
    ($this->on)($this->salma, null)->get(route('app.cards'))->assertInertia(fn (Assert $page): Assert => $page->where('tenants', null));
});

it('shows each user only their own: a franchisee its shop, another organization nothing of this one', function (): void {
    $franchisee = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $elsewhere = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner);

    ($this->on)($franchisee, null)->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('tenants.groups', [['organization' => 'Pâtisserie Amal', 'entries' => [
            ($this->entry)('business:'.$this->tenants->a1->id, 'Amal Gauthier', 'Owner', true),
        ]]]));
    ($this->on)($elsewhere, null)->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('tenants.groups', [['organization' => 'Café Hafa', 'entries' => [
            ($this->entry)('business:'.$this->tenants->b1->id, 'Café Hafa', 'Owner', true),
        ]]]));
});

it('leaves out an archived organization, and offers a café\'s organization to an admin who works in none of it', function (): void {
    $this->context->bypass(fn () => $this->tenants->orgA->forceFill(['archived_at' => now()])->save());
    $headOffice = $this->tenants->admin(User::factory()->create(), $this->tenants->orgB);

    ($this->on)($this->salma, null)->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('tenants.groups', [['organization' => 'Café Hafa', 'entries' => [
            ($this->entry)('business:'.$this->tenants->b1->id, 'Café Hafa', 'Owner', true),
        ]]]));
    // Runs the café's organization without working in the café (after a hand-over): where ResolveTenant puts them.
    ($this->on)($headOffice, null)->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('tenants.current', ($this->entry)('org:'.$this->tenants->orgB->id, 'Whole network', 'Runs the network', true)));
    ($this->on)($headOffice, null)->put(route('tenant.update'), ['choice' => 'org:'.$this->tenants->orgB->id])->assertRedirect(route('dashboard'));
});

it('switches to a business or a network the user works in, then opens its dashboard', function (string $to): void {
    $choice = ['A1' => 'business:'.$this->tenants->a1->id, 'B1' => 'business:'.$this->tenants->b1->id, 'network A' => 'org:'.$this->tenants->orgA->id][$to];

    ($this->on)($this->salma, 'business:'.$this->tenants->a2->id)->put(route('tenant.update'), ['choice' => $choice])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(SetTenant::SESSION_KEY, $choice);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page->where('tenants.current.choice', $choice));
})->with(['A1', 'B1', 'network A']);

it('switches nothing, with a 404, to what the user may not work in', function (Closure $choice): void {
    $start = 'business:'.$this->tenants->b1->id;

    ($this->on)($this->salma, $start)->put(route('tenant.update'), ['choice' => $choice($this)])
        ->assertNotFound()
        ->assertSessionHas(SetTenant::SESSION_KEY, $start);
})->with([
    'a membership removed' => [function ($test): string {
        $test->context->bypass(fn () => BusinessMember::query()->where('user_id', $test->salma->id)->where('business_id', $test->tenants->a1->id)->delete());

        return 'business:'.$test->tenants->a1->id;
    }],
    'a business suspended' => [function ($test): string {
        $test->context->bypass(fn () => $test->tenants->a2->forceFill(['status' => BusinessStatus::Suspended])->save());

        return 'business:'.$test->tenants->a2->id;
    }],
    'another tenant\'s business' => [function ($test): string {
        // In Salma's network, but she doesn't work there.
        $other = $test->context->bypass(fn (): Business => Business::factory()->for($test->tenants->orgA)->create());

        return 'business:'.$other->id;
    }],
    'a network the user does not run' => [function ($test): string {
        $test->context->bypass(fn () => $test->tenants->orgA->admins()->detach($test->salma->id));

        return 'org:'.$test->tenants->orgA->id;
    }],
    'a business that does not exist' => [fn ($test): string => 'business:999999'],
    // Her café is the account: she runs its organization, but the menu offers the café, never a network of one.
    'her café\'s own organization' => [fn ($test): string => 'org:'.$test->tenants->orgB->id],
]);

it('refuses a choice it cannot read', function (string $choice): void {
    ($this->on)($this->salma, null)->put(route('tenant.update'), ['choice' => $choice])->assertSessionHasErrors('choice');
})->with(['', 'business:', 'business:0', 'shop:3', 'business:3; drop']);

it('lets a franchisee\'s owner switch only among their own', function (): void {
    $owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);

    ($this->on)($owner, null)->put(route('tenant.update'), ['choice' => 'business:'.$this->tenants->a2->id])->assertNotFound();
    ($this->on)($owner, null)->put(route('tenant.update'), ['choice' => 'org:'.$this->tenants->orgA->id])->assertNotFound();
    ($this->on)($owner, null)->put(route('tenant.update'), ['choice' => 'business:'.$this->tenants->b1->id])->assertNotFound();
});

it('keeps an admin signed in as an owner in the business they opened', function (): void {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
    $this->actingAs($admin);
    app(StartImpersonation::class)->handle($admin, $this->tenants->b1, $this->salma, app('session')->driver());

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('tenants.groups', [['organization' => 'Café Hafa', 'entries' => [
            ($this->entry)('business:'.$this->tenants->b1->id, 'Café Hafa', 'Owner', true),
        ]]]));
    $this->put(route('tenant.update'), ['choice' => 'business:'.$this->tenants->a1->id])->assertStatus(303);

    expect(session(SetTenant::SESSION_KEY))->toBe('business:'.$this->tenants->b1->id);
});

it('asks where they work today when no business is chosen', function (): void {
    ($this->on)($this->salma, null)->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('dashboard')
        ->where('tenants.current', null)
        ->count('tenants.groups', 2)
        ->where('stats', null)
        ->where('checklist', null));
});
