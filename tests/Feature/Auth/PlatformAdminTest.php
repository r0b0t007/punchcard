<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Enums\BusinessRole;
use App\Enums\PlatformRole;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The platform admin (CHW-22)
|--------------------------------------------------------------------------
|
| The only role spatie/laravel-permission holds. An admin opens the Filament
| panel at /admin and passes every ability there; in the app itself they
| have no business or organization rights. Granted only by punchcard:admin.
|
*/

beforeEach(function (): void {
    $this->admin = User::factory()->withTwoFactor()->create(['email' => 'ops@example.test']);
    Artisan::call('punchcard:admin', ['email' => 'ops@example.test']);
    $this->admin->refresh();
});

it('opens the Filament panel to a platform admin only', function (): void {
    $tenants = Tenants::make();
    $orgAdmin = $tenants->admin(User::factory()->create(), $tenants->orgA);
    $owner = $tenants->member(User::factory()->create(), $tenants->a1, BusinessRole::Owner);

    $this->actingAs($this->admin)->get('/admin')->assertOk();

    foreach ([$orgAdmin, $owner, User::factory()->create()] as $user) {
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }
});

it('sends a guest to the app\'s sign-in, which asks for two-factor authentication', function (): void {
    $this->get('/admin')->assertRedirect(route('login'));
    $this->get('/admin/login')->assertNotFound();

    $this->post(route('login.store'), ['email' => 'ops@example.test', 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

it('keeps the panel shut in local too', function (): void {
    // Without FilamentUser, Filament let anyone signed in through when app.env is local (CHW-131).
    config(['app.env' => 'local']);

    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    $this->actingAs($this->admin)->get('/admin')->assertOk();
});

it('never opens the panel to an admin unverified, without two-factor authentication, or anonymised', function (string $state): void {
    $this->admin->forceFill(match ($state) {
        'unverified' => ['email_verified_at' => null],
        'without two-factor' => ['two_factor_confirmed_at' => null],
        'anonymised' => ['anonymised_at' => now()],
    })->save();

    $this->actingAs($this->admin)->get('/admin')->assertForbidden();
})->with(['unverified', 'without two-factor', 'anonymised']);

it('lets an admin through every ability inside the panel, and nowhere else', function (): void {
    Gate::define('close-a-business', fn (): bool => false);

    expect(Gate::forUser($this->admin)->allows('close-a-business'))->toBeFalse();

    Filament::setCurrentPanel('admin');
    Filament::setServingStatus();

    try {
        expect(Gate::forUser($this->admin)->allows('close-a-business'))->toBeTrue()
            ->and(Gate::forUser(User::factory()->create())->allows('close-a-business'))->toBeFalse();
    } finally {
        Filament::setServingStatus(false);
    }
});

it('grants and revokes the role from the command, and only to a real account', function (): void {
    expect($this->admin->hasRole(PlatformRole::Admin->value))->toBeTrue()
        ->and(Artisan::call('punchcard:admin', ['email' => 'ops@example.test', '--revoke' => true]))->toBe(0)
        ->and($this->admin->fresh()?->hasRole(PlatformRole::Admin->value))->toBeFalse()
        ->and(Artisan::call('punchcard:admin', ['email' => 'nobody@example.test']))->toBe(1);

    User::factory()->withTwoFactor()->create(['email' => 'gone@example.test', 'anonymised_at' => now()]);

    expect(Artisan::call('punchcard:admin', ['email' => 'gone@example.test']))->toBe(1)
        ->and(User::role(PlatformRole::Admin->value)->count())->toBe(0);
});

it('grants the role only to an account that could get in', function (string $state, string $message): void {
    $user = User::factory()->withTwoFactor()->create(['email' => 'new-ops@example.test']);
    $user->forceFill($state === 'unverified' ? ['email_verified_at' => null] : ['two_factor_confirmed_at' => null])->save();

    expect(Artisan::call('punchcard:admin', ['email' => 'new-ops@example.test']))->toBe(1)
        ->and(Artisan::output())->toContain($message)
        ->and($user->fresh()?->hasRole(PlatformRole::Admin->value))->toBeFalse();
})->with([
    'unverified' => ['unverified', 'Verify the email first.'],
    'without two-factor' => ['without two-factor', 'Turn on two-factor authentication first.'],
]);

it('finds the account whatever the case of the email typed', function (): void {
    expect(Artisan::call('punchcard:admin', ['email' => 'Ops@Example.TEST', '--revoke' => true]))->toBe(0)
        ->and($this->admin->fresh()?->hasRole(PlatformRole::Admin->value))->toBeFalse();
});

it('never creates the role to revoke it', function (): void {
    Role::query()->delete();
    User::factory()->create(['email' => 'someone@example.test']);

    expect(Artisan::call('punchcard:admin', ['email' => 'someone@example.test', '--revoke' => true]))->toBe(0)
        ->and(Role::query()->count())->toBe(0);
});

it('takes the role away with the account, deleted or anonymised', function (string $how): void {
    if ($how === 'anonymised') {
        $tenants = Tenants::make();
        $tenants->stamp($tenants->enroll($this->admin, $tenants->cardA), $tenants->a1);
    }

    app(DeleteAccount::class)->handle($this->admin);

    expect(DB::table('model_has_roles')->where('model_id', $this->admin->id)->count())->toBe(0)
        ->and($how === 'anonymised' ? $this->admin->fresh()?->isAnonymised() : User::query()->find($this->admin->id))
        ->toBe($how === 'anonymised' ? true : null);
})->with(['deleted', 'anonymised']);
