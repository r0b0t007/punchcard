<?php

declare(strict_types=1);

use App\Actions\Admin\ImpersonationRefused;
use App\Actions\Admin\StartImpersonation;
use App\Actions\Admin\StopImpersonation;
use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\PlatformRole;
use App\Filament\Resources\Businesses\Pages\ViewBusiness;
use App\Http\Middleware\WhileImpersonating;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use App\Support\Auth\Impersonation;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Contracts\Session\Session;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Signing in as a business owner (CHW-144)
|--------------------------------------------------------------------------
|
| The platform admin signs in as an owner of a business, from its page in
| the admin panel, after typing their own password again. For 30 minutes
| they read that business's app as the owner does, in the owner's tenant
| (no bypass), and change nothing; then, or when they ask, or sign out,
| they are back in admin. Start and end are in the audit log, as the admin.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-22 10:00:00');
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->admin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
    $this->owner = $this->tenants->member(User::factory()->create(['name' => 'Salma Owner', 'last_active_at' => '2026-10-01 08:00:00']), $this->tenants->a2, BusinessRole::Owner);

    $this->session = fn (): Session => app('session')->driver();
    $this->start = function (?User $owner = null, ?Business $business = null, ?User $admin = null): Impersonation {
        $this->actingAs($admin ?? $this->admin);

        return app(StartImpersonation::class)->handle($admin ?? $this->admin, $business ?? $this->tenants->a2, $owner ?? $this->owner, ($this->session)());
    };
    $this->audits = fn (): array => $this->context->bypass(fn (): array => AuditLog::query()->where('action', 'like', 'impersonation.%')->orderBy('id')
        ->get(['actor_id', 'actor_label', 'action', 'subject_id', 'reason', 'context'])
        ->map(fn (AuditLog $entry): array => $entry->only(['actor_id', 'actor_label', 'action', 'subject_id', 'reason', 'context']))
        ->all());
});

afterEach(function (): void {
    Filament::setServingStatus(false);
    Carbon::setTestNow();
});

it('starts from the business\'s page once the admin types their password again', function (): void {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setServingStatus();
    // As the panel's requests run: in bypass (PlatformAdminWorksAcrossTenants).
    $act = fn (string $password): Testable => $this->context->bypass(fn (): Testable => Livewire::test(ViewBusiness::class, ['record' => $this->tenants->a2->getRouteKey()])
        ->callAction('impersonate', ['owner' => $this->owner->id, 'password' => $password]));

    $act('not-it')->assertHasActionErrors(['password']);
    expect(Auth::id())->toBe($this->admin->id)->and(($this->audits)())->toBe([]);

    $act('password')->assertRedirect(route('dashboard'));

    expect(Auth::id())->toBe($this->owner->id)
        ->and(($this->audits)())->toBe([[
            'actor_id' => $this->admin->id,
            'actor_label' => 'admin #'.$this->admin->id,
            'action' => 'impersonation.started',
            'subject_id' => $this->tenants->a2->id,
            'reason' => null,
            'context' => ['admin_id' => $this->admin->id, 'owner_id' => $this->owner->id, 'ends_at' => '2026-10-22T10:30:00+00:00'],
        ]]);
});

it('signs in as an owner of that business only, a real person, at a business that operates', function (Closure $setUp, string $message): void {
    [$owner, $business, $admin] = $setUp->call($this);

    expect(fn () => ($this->start)($owner, $business, $admin))->toThrow(ImpersonationRefused::class, $message)
        ->and(Auth::id())->toBe(($admin ?? $this->admin)->id)
        ->and(Impersonation::of(($this->session)()))->toBeNull()
        ->and(($this->audits)())->toBe([]);
})->with([
    'staff' => [fn (): array => [$this->tenants->member(User::factory()->create(), $this->tenants->a2), null, null], 'not an owner'],
    'an owner elsewhere' => [fn (): array => [$this->owner, $this->tenants->a1, null], 'not an owner'],
    'an org admin who owns nothing' => [fn (): array => [$this->tenants->admin(User::factory()->create(), $this->tenants->orgA), null, null], 'not an owner'],
    'an owner who is a platform admin' => [function (): array {
        $this->owner->assignRole(PlatformRole::Admin->value);

        return [$this->owner, null, null];
    }, 'a platform admin, or as yourself'],
    'the admin themselves' => [fn (): array => [$this->tenants->member($this->admin, $this->tenants->a2, BusinessRole::Owner), null, null], 'a platform admin, or as yourself'],
    'an anonymised owner' => [function (): array {
        $this->owner->forceFill(['anonymised_at' => now()])->save();

        return [$this->owner, null, null];
    }, 'deleted'],
    'a suspended business' => [function (): array {
        $this->context->bypass(fn () => $this->tenants->a2->forceFill(['status' => BusinessStatus::Suspended])->save());

        return [$this->owner, null, null];
    }, 'suspended or closed'],
    'someone who is not the platform admin' => [fn (): array => [$this->owner, null, $this->tenants->member(User::factory()->withTwoFactor()->create(), $this->tenants->a2, BusinessRole::Owner)], 'Only the platform admin'],
]);

it('signs in as one owner at a time', function (): void {
    ($this->start)();
    $this->actingAs($this->admin);

    expect(fn () => app(StartImpersonation::class)->handle($this->admin, $this->tenants->a2, $this->owner, ($this->session)()))
        ->toThrow(ImpersonationRefused::class, 'already');
});

it('works in the owner\'s business, without the admin\'s reach across tenants', function (): void {
    // They also work at another organization: the business the admin chose is the one worked in.
    $this->tenants->member($this->owner, $this->tenants->b1);
    ($this->start)();
    $elsewhere = $this->tenants->cardB;

    $this->get(route('cards.edit', $elsewhere->id))->assertNotFound();
    $this->get(route('staff.index'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('impersonating', [
            'owner' => 'Salma Owner',
            'business' => $this->tenants->a2->name,
            'endsAt' => '2026-10-22T10:30:00+00:00',
        ]));
});

it('shares nothing about impersonating with anyone else', function (): void {
    $this->actingAs($this->owner)->get(route('staff.index'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('impersonating', null));
});

it('goes back to admin when the time is up', function (): void {
    ($this->start)();

    $this->travelTo(now()->addMinutes(29));
    $this->get(route('staff.index'))->assertOk();
    expect(Auth::id())->toBe($this->owner->id);

    $this->travelTo(now()->addMinutes(2));
    $this->get(route('staff.index'))->assertRedirect(route('filament.admin.resources.businesses.view', $this->tenants->a2->id));

    expect(Auth::id())->toBe($this->admin->id)
        ->and(Impersonation::of(($this->session)()))->toBeNull()
        ->and(collect(($this->audits)())->last())->toMatchArray(['actor_id' => $this->admin->id, 'action' => 'impersonation.ended', 'reason' => 'expired', 'context' => ['admin_id' => $this->admin->id, 'owner_id' => $this->owner->id]]);
});

it('goes back to admin when asked, or on signing out, without signing the owner out anywhere', function (string $how, string $reason): void {
    ($this->start)();
    $remember = $this->owner->fresh()?->remember_token;

    $response = $how === 'back' ? $this->post(route('impersonation.stop')) : $this->post(route('logout'));

    $response->assertRedirect(route('filament.admin.resources.businesses.view', $this->tenants->a2->id));
    expect(Auth::id())->toBe($this->admin->id)
        ->and(($this->session)()->get('tenant'))->toBeNull()
        ->and($this->owner->fresh()?->remember_token)->toBe($remember)
        ->and(collect(($this->audits)())->last())->toMatchArray(['actor_label' => 'admin #'.$this->admin->id, 'action' => 'impersonation.ended', 'reason' => $reason]);

    $this->get('/admin')->assertOk();
})->with([
    'Back to admin' => ['back', StopImpersonation::BACK],
    'Log out' => ['logout', StopImpersonation::SIGNED_OUT],
]);

it('lets only the business app\'s pages be read, and going back', function (): void {
    $allowed = collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn (Illuminate\Routing\Route $route): array => array_map(fn (string $method): array => [$route, $method], $route->methods()))
        ->filter(fn (array $pair): bool => Impersonation::allows($pair[0], $pair[1]))
        ->map(fn (array $pair): string => $pair[1].' '.$pair[0]->uri())
        ->unique()->sort()->values()->all();

    expect($allowed)->toBe([
        'GET business', 'GET business/cards', 'GET business/cards/create', 'GET business/cards/{card}/edit', 'GET business/counter',
        'GET business/counter/scan', 'GET business/counter/scans/{scan}', 'GET business/onboarding', 'GET business/onboarding/{step}',
        'GET business/qr-stand', 'GET business/staff', 'GET business/stampers', 'GET business/verification', 'GET email/verify', 'GET start',
        'HEAD business', 'HEAD business/cards', 'HEAD business/cards/create', 'HEAD business/cards/{card}/edit', 'HEAD business/counter',
        'HEAD business/counter/scan', 'HEAD business/counter/scans/{scan}', 'HEAD business/onboarding', 'HEAD business/onboarding/{step}',
        'HEAD business/qr-stand', 'HEAD business/staff', 'HEAD business/stampers', 'HEAD business/verification', 'HEAD email/verify', 'HEAD start',
        'POST impersonation/stop',
    ]);
});

it('refuses every change, and the owner\'s own account, customer side, taps and the admin panel', function (string $method, Closure $uri): void {
    ($this->start)();
    $before = $this->owner->fresh()?->only(['name', 'email', 'password', 'two_factor_secret', 'marketing_opt_in_at']);

    $this->call($method, $uri->call($this), ['name' => 'Changed', 'email' => 'changed@example.com', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1', 'qty' => 1, 'key' => 'k', 'enabled' => true])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');

    expect(Auth::id())->toBe($this->owner->id)
        ->and($this->owner->fresh()?->only(['name', 'email', 'password', 'two_factor_secret', 'marketing_opt_in_at']))->toBe($before);
})->with([
    'a card saved' => ['PUT', fn (): string => route('cards.update', $this->tenants->cardA->id)],
    'a stamper switched' => ['PATCH', fn (): string => route('stampers.status', $this->tenants->stamper($this->tenants->a2)->id)],
    'a staff invitation' => ['POST', fn (): string => route('staff.invitations.store')],
    'a stamper armed' => ['POST', fn (): string => route('counter.arm', $this->tenants->stamper($this->tenants->a2)->id)],
    'a QR scanned' => ['POST', fn (): string => route('counter.scans.store')],
    'an onboarding step' => ['POST', fn (): string => route('onboarding.business')],
    'the QR stand printed' => ['POST', fn (): string => route('business.qr-stand.printed')],
    'the profile' => ['PATCH', fn (): string => route('profile.update')],
    'the password' => ['PUT', fn (): string => route('user-password.update')],
    'two-factor' => ['POST', fn (): string => route('two-factor.enable')],
    'news by email' => ['PATCH', fn (): string => route('privacy.marketing')],
    'the data export' => ['POST', fn (): string => route('privacy.export')],
    'deleting the account' => ['DELETE', fn (): string => route('profile.destroy')],
    'the settings' => ['GET', fn (): string => route('profile.edit')],
    'the security page' => ['GET', fn (): string => route('security.edit')],
    'their cards' => ['GET', fn (): string => route('app.cards')],
    'their member QR' => ['GET', fn (): string => route('app.qr')],
    'a tap' => ['GET', fn (): string => '/t?e=00&c=00'],
    'the admin panel' => ['GET', fn (): string => '/admin'],
]);

it('shows an owner who hasn\'t verified their email what they see: the page asking them to', function (): void {
    $this->owner->forceFill(['email_verified_at' => null])->save();
    ($this->start)();

    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    $this->get(route('verification.notice'))->assertOk();
    $this->post(route('verification.send'))->assertRedirect();

    expect(Auth::id())->toBe($this->owner->id);
});

it('just refuses pages opened one after another, in one tab or two', function (): void {
    ($this->start)();

    $this->get(route('app.cards'))->assertRedirect(route('dashboard'));
    $this->get(route('app.qr'))->assertRedirect(route('dashboard'));
    $this->get(route('staff.index'))->assertOk();
    $this->get(route('profile.edit'))->assertRedirect(route('dashboard'));

    expect(Auth::id())->toBe($this->owner->id);
});

it('goes back to admin instead of round in circles when the owner no longer runs the business', function (Closure $change): void {
    ($this->start)();
    $change->call($this);

    // No business to resolve: the dashboard sends on to My cards, refused again.
    $this->get(route('app.cards'))->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertRedirect(route('app.cards'));
    $this->get(route('app.cards'))->assertRedirect(route('filament.admin.resources.businesses.view', $this->tenants->a2->id));

    expect(Auth::id())->toBe($this->admin->id)
        ->and(collect(($this->audits)())->last())->toMatchArray(['action' => 'impersonation.ended', 'reason' => StopImpersonation::REFUSED_TWICE]);
})->with([
    'removed as owner' => [fn () => $this->context->bypass(fn () => $this->tenants->a2->members()->detach($this->owner->id))],
    'the business suspended' => [fn () => $this->context->bypass(fn () => $this->tenants->a2->forceFill(['status' => BusinessStatus::Suspended])->save())],
]);

it('shows each end\'s reason in the admin\'s language in the business\'s audit log', function (): void {
    ($this->start)();
    $this->post(route('impersonation.stop'));
    app()->setLocale('fr');
    Filament::setCurrentPanel('admin');
    Filament::setServingStatus();

    $this->context->bypass(fn () => Livewire::test(ViewBusiness::class, ['record' => $this->tenants->a2->getRouteKey()])
        ->assertSee('Retour à l’administration')
        ->assertDontSee('>back<', false));
});

it('ends when either account goes, or the admin loses the panel, and says who it was', function (Closure $change, string $reason, ?bool $adminBack): void {
    ($this->start)();
    $change->call($this);
    // A new request reads the signed-in account again; the test's guard would keep the one it has.
    Auth::forgetGuards();

    $this->get(route('staff.index'))->assertRedirect();

    expect(Auth::check() ? Auth::id() : null)->toBe($adminBack ? $this->admin->id : null)
        ->and(Impersonation::of(($this->session)()))->toBeNull()
        ->and(collect(($this->audits)())->last())->toMatchArray([
            'actor_id' => $this->admin->id,
            'action' => 'impersonation.ended',
            'reason' => $reason,
            'context' => ['admin_id' => $this->admin->id, 'owner_id' => $this->owner->id],
        ]);
})->with([
    'the owner\'s account deleted' => [fn () => $this->owner->forceFill(['anonymised_at' => now()])->save(), StopImpersonation::OWNER_GONE, true],
    'the admin\'s account deleted' => [function (): void {
        $this->admin->syncRoles([]);
        $this->admin->forceFill(['anonymised_at' => now()])->save();
    }, StopImpersonation::ADMIN_GONE, false],
    'the admin no longer an admin' => [fn () => $this->admin->syncRoles([]), StopImpersonation::ADMIN_GONE, false],
]);

it('sends an Inertia visit after the time limit to the admin panel as a full page', function (): void {
    ($this->start)();
    $this->travelTo(now()->addMinutes(31));

    $this->get(route('staff.index'), ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('filament.admin.resources.businesses.view', $this->tenants->a2->id));
});

it('refuses the panel\'s Livewire updates, and toasts nothing for a link only prefetched', function (): void {
    ($this->start)();

    $this->post(route('livewire.update'), ['components' => []])->assertRedirect();
    $this->get(route('app.cards'), ['Purpose' => 'prefetch'])->assertRedirect(route('dashboard'))->assertInertiaFlashMissing('toast');
    expect(Auth::id())->toBe($this->owner->id);
});

it('checks every route with a session', function (): void {
    $router = app('router');
    $unchecked = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (Illuminate\Routing\Route $route): bool => in_array(StartSession::class, $router->gatherRouteMiddleware($route), true))
        ->reject(fn (Illuminate\Routing\Route $route): bool => in_array(WhileImpersonating::class, $router->gatherRouteMiddleware($route), true))
        ->map(fn (Illuminate\Routing\Route $route): string => $route->uri())
        ->values()->all();

    expect($unchecked)->toBe([]);
});

it('sends a refused change back to the page with a GET, never the same PUT, PATCH or DELETE again', function (): void {
    ($this->start)();

    $this->from(route('onboarding.step', 'location'))->put(route('onboarding.location'))
        ->assertStatus(303)->assertRedirect(route('onboarding.step', 'location'));
    $this->from(route('counter.show'))->delete(route('counter.disarm', $this->tenants->stamper($this->tenants->a2)->id))
        ->assertStatus(303)->assertRedirect(route('counter.show'));
});

it('refuses in the language the page is read in', function (): void {
    $this->owner->forceFill(['locale' => 'fr'])->save();
    ($this->start)();

    $this->get(route('app.cards'))->assertInertiaFlash('toast.message', 'Pas tant que vous êtes connecté en tant que propriétaire : rien n’a été modifié.');
});

it('records the end when the owner\'s password changes elsewhere and the session is signed out', function (): void {
    ($this->start)();
    $this->owner->forceFill(['password' => Hash::make('changed-on-their-phone')])->save();
    Auth::forgetGuards();

    $this->get(route('staff.index'))->assertRedirect();

    expect(Auth::check())->toBeFalse()
        ->and(Impersonation::of(($this->session)()))->toBeNull()
        ->and(collect(($this->audits)())->last())->toMatchArray([
            'actor_id' => $this->admin->id,
            'action' => 'impersonation.ended',
            'reason' => StopImpersonation::OWNER_GONE,
        ]);
});

it('names the admin in the session row while they are signed in as the owner', function (): void {
    $handler = app('session')->driver('database')->getHandler();
    $sessionOf = fn (string $id): mixed => DB::table('sessions')->where('id', $id)->value('user_id');

    $this->actingAs($this->owner);
    $handler->write('owners-own', serialize([]));
    ($this->start)();
    // A new session id: a row of its own (the handler would update the first otherwise).
    $handler->setExists(false)->write('impersonating', serialize([]));

    expect($sessionOf('owners-own'))->toBe($this->owner->id)
        ->and($sessionOf('impersonating'))->toBe($this->admin->id);
});

it('offers signing in as the owner only where it can work', function (): void {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setServingStatus();
    $page = fn (): Testable => $this->context->bypass(fn (): Testable => Livewire::test(ViewBusiness::class, ['record' => $this->tenants->a2->getRouteKey()]));

    $page()->assertActionVisible('impersonate');
    $this->context->bypass(fn () => $this->tenants->a2->forceFill(['status' => BusinessStatus::Suspended])->save());
    $page()->assertActionHidden('impersonate');
});

it('never counts the admin\'s reading as the owner\'s use of their account', function (): void {
    ($this->start)();

    $this->get(route('staff.index'))->assertOk();

    expect($this->owner->fresh()?->last_active_at?->toDateTimeString())->toBe('2026-10-01 08:00:00');
});
