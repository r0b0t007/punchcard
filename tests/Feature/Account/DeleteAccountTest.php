<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Enums\BusinessRole;
use App\Enums\RewardStatus;
use App\Models\BusinessMember;
use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Deleting an account (CHW-139)
|--------------------------------------------------------------------------
|
| The stamp ledger is permanent and nothing it points at can be deleted.
| So an account with no history is deleted as before, and one with history
| (stamps as a customer or as staff) is anonymised: the row and its id stay,
| so the ledger, counters and rewards stay true, but nothing in it identifies
| the person and nobody can sign in to it again. An owner or org admin must
| hand over or close their business first.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->deleteAccount = fn (User $user): TestResponse => $this->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password']);
});

it('deletes an account with no history, with its passkeys and unstamped cards', function (): void {
    $user = User::factory()->create();
    $user->passkeys()->create(['name' => 'Phone', 'credential_id' => 'cred-1', 'credential' => []]);
    $enrollment = $this->tenants->enroll($user, $this->tenants->cardA);

    ($this->deleteAccount)($user)->assertSessionHasNoErrors()->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull()
        ->and(DB::table('passkeys')->where('user_id', $user->id)->count())->toBe(0)
        ->and($this->context->bypass(fn (): bool => CardEnrollment::query()->whereKey($enrollment->id)->exists()))->toBeFalse();
});

it('anonymises a customer with stamps and keeps the ledger, progress and rewards true', function (): void {
    $user = User::factory()->create(['email' => 'salma@example.com']);
    $user->passkeys()->create(['name' => 'Phone', 'credential_id' => 'cred-1', 'credential' => []]);
    DB::table('sessions')->insert(['id' => 'session-1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    DB::table('password_reset_tokens')->insert(['email' => 'salma@example.com', 'token' => 'x', 'created_at' => now()]);

    $enrollment = $this->tenants->enroll($user, $this->tenants->cardA);
    $this->tenants->stamp($enrollment, $this->tenants->a1);
    $this->context->bypass(fn () => $enrollment->forceFill(['current_stamps' => 1, 'lifetime_stamps' => 1])->save());
    $reward = $this->tenants->reward($enrollment);

    ($this->deleteAccount)($user)->assertSessionHasNoErrors()->assertRedirect(route('home'));

    $this->assertGuest();
    $anonymised = $user->fresh();

    expect($anonymised)->not->toBeNull()
        ->and($anonymised->name)->toBe('Deleted user')
        ->and($anonymised->email)->toBe("deleted-{$user->id}@deleted.invalid")
        ->and($anonymised->email_verified_at)->toBeNull()
        ->and($anonymised->remember_token)->toBeNull()
        ->and($anonymised->two_factor_secret)->toBeNull()
        ->and($anonymised->two_factor_recovery_codes)->toBeNull()
        ->and($anonymised->isAnonymised())->toBeTrue()
        ->and(Hash::check('password', $anonymised->password))->toBeFalse()
        ->and(DB::table('passkeys')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', 'salma@example.com')->count())->toBe(0);

    $this->context->bypass(function () use ($enrollment, $reward): void {
        $kept = $enrollment->fresh();

        expect(StampEvent::query()->where('enrollment_id', $enrollment->id)->count())->toBe(1)
            ->and($kept->current_stamps)->toBe(1)
            ->and($kept->referral_code)->toBeNull()
            ->and(Reward::query()->whereKey($reward->id)->value('status'))->toBe(RewardStatus::Available);
    });
});

it('lets nobody sign in to an anonymised account, and frees its email', function (): void {
    $user = User::factory()->create(['email' => 'salma@example.com']);
    $this->tenants->stamp($this->tenants->enroll($user, $this->tenants->cardA), $this->tenants->a1);
    ($this->deleteAccount)($user);

    $this->post(route('login.store'), ['email' => 'salma@example.com', 'password' => 'password']);
    $this->assertGuest();

    $this->post(route('login.store'), ['email' => "deleted-{$user->id}@deleted.invalid", 'password' => 'password']);
    $this->assertGuest();

    $this->post(route('register.store'), [
        'name' => 'Salma', 'email' => 'salma@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    expect(User::query()->where('email', 'salma@example.com')->value('id'))->not->toBe($user->id);
});

it('anonymises a staff member who recorded stamps, and removes their membership', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff);
    $customer = $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA);
    $this->tenants->stamp($customer, $this->tenants->a1, ['staff_id' => $staff->id]);

    ($this->deleteAccount)($staff)->assertSessionHasNoErrors();

    expect($staff->fresh()?->isAnonymised())->toBeTrue()
        ->and($this->context->bypass(fn (): int => BusinessMember::query()->where('user_id', $staff->id)->count()))->toBe(0)
        ->and($this->context->bypass(fn (): ?int => StampEvent::query()->where('enrollment_id', $customer->id)->value('staff_id')))->toBe($staff->id);
});

it('deletes a staff member with no history, and their membership', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff);

    ($this->deleteAccount)($staff)->assertSessionHasNoErrors();

    expect($staff->fresh())->toBeNull()
        ->and($this->context->bypass(fn (): int => BusinessMember::query()->where('user_id', $staff->id)->count()))->toBe(0);
});

it('refuses deleting the account of an owner or org admin until they hand over', function (string $role): void {
    $user = User::factory()->create();

    match ($role) {
        'owner' => $this->tenants->member($user, $this->tenants->a1, BusinessRole::Owner),
        'org admin' => $this->tenants->admin($user, $this->tenants->orgA),
    };

    ($this->deleteAccount)($user)->assertSessionHasErrors('account')->assertRedirect(route('profile.edit'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()?->isAnonymised())->toBeFalse();
})->with(['owner', 'org admin']);

it('refuses an owner in the Action too, whoever calls it', function (): void {
    $owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);

    app(DeleteAccount::class)->handle($owner);
})->throws(LogicException::class);
