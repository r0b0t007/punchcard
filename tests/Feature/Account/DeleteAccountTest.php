<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Enums\BusinessRole;
use App\Enums\RewardStatus;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\BusinessMember;
use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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
    $user = User::factory()->withTwoFactor()->create(['email' => 'salma@example.com', 'locale' => 'fr']);
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
        ->and($anonymised->email)->toMatch("/^deleted-{$user->id}-[a-z0-9]{20}@deleted\\.invalid$/")
        ->and($anonymised->locale)->toBeNull()
        ->and($anonymised->two_factor_confirmed_at)->toBeNull()
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

    $this->post(route('login.store'), ['email' => $user->fresh()?->email, 'password' => 'password']);
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

it('anonymises a staff member who only redeemed rewards, keeping who redeemed them', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff);
    $reward = $this->tenants->reward($this->tenants->enroll(User::factory()->create(), $this->tenants->cardA));
    $this->context->bypass(fn () => $reward->forceFill([
        'status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_by' => $staff->id, 'redeemed_business_id' => $this->tenants->a1->id,
    ])->save());

    ($this->deleteAccount)($staff)->assertSessionHasNoErrors();

    expect($staff->fresh()?->isAnonymised())->toBeTrue()
        ->and($this->context->bypass(fn (): ?int => $reward->fresh()?->redeemed_by))->toBe($staff->id);
});

it('drops the cards an anonymised customer never stamped', function (): void {
    $user = User::factory()->create();
    $stamped = $this->tenants->enroll($user, $this->tenants->cardA);
    $this->tenants->stamp($stamped, $this->tenants->a1);
    $unstamped = $this->tenants->enroll($user, $this->tenants->cardB);

    ($this->deleteAccount)($user);

    $this->context->bypass(function () use ($stamped, $unstamped): void {
        expect(CardEnrollment::query()->whereKey($stamped->id)->exists())->toBeTrue()
            ->and(CardEnrollment::query()->whereKey($unstamped->id)->exists())->toBeFalse();
    });
});

it('signs the account out on every other device, whatever the session driver', function (): void {
    $user = User::factory()->create(['email' => 'salma@example.com']);
    $this->tenants->stamp($this->tenants->enroll($user, $this->tenants->cardA), $this->tenants->a1);

    // Signed in on this device; the account is deleted from another one.
    $this->post(route('login.store'), ['email' => 'salma@example.com', 'password' => 'password']);
    $this->get(route('profile.edit'))->assertOk();
    app(DeleteAccount::class)->handle($user);
    // A new request loads the user afresh; the test app would otherwise reuse the guard's cached one.
    $this->app['auth']->forgetGuards();

    $this->get(route('profile.edit'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('anonymises instead when the ledger refuses the delete after all', function (): void {
    $user = User::factory()->create();
    $enrollment = $this->tenants->enroll($user, $this->tenants->cardA);

    // History arrives between the check and the delete: the cascade now hits the ledger.
    User::deleting(fn () => $this->tenants->stamp($enrollment, $this->tenants->a1));

    app(DeleteAccount::class)->handle($user);

    expect($user->fresh()?->isAnonymised())->toBeTrue();
});

it('reserves the anonymised email domain, so nobody can take an address before the account gets it', function (string $how): void {
    $squatter = User::factory()->create();
    $victim = User::factory()->create();
    $this->tenants->stamp($this->tenants->enroll($victim, $this->tenants->cardA), $this->tenants->a1);
    $reserved = "deleted-{$victim->id}-abc@deleted.invalid";

    $response = match ($how) {
        'signup' => $this->post(route('register.store'), ['name' => 'X', 'email' => $reserved, 'password' => 'password', 'password_confirmation' => 'password']),
        'profile' => $this->actingAs($squatter)->patch(route('profile.update'), ['name' => 'X', 'email' => strtoupper($reserved)]),
    };

    $response->assertSessionHasErrors('email');
    app(DeleteAccount::class)->handle($victim);
    expect($victim->fresh()?->isAnonymised())->toBeTrue();
})->with(['signup', 'profile']);

it('sends no password reset to an anonymised account, by its old email or its new one', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'salma@example.com']);
    $this->tenants->stamp($this->tenants->enroll($user, $this->tenants->cardA), $this->tenants->a1);
    app(DeleteAccount::class)->handle($user);

    $this->post(route('password.email'), ['email' => 'salma@example.com']);
    $this->post(route('password.email'), ['email' => $user->fresh()?->email]);

    Notification::assertNothingSent();
});

it('treats a reward on the customer\'s card as history', function (): void {
    $user = User::factory()->create();
    $this->tenants->reward($this->tenants->enroll($user, $this->tenants->cardA));

    app(DeleteAccount::class)->handle($user);

    expect($user->fresh()?->isAnonymised())->toBeTrue();
});

it('removes a staff member from every business, and nobody else', function (): void {
    $staff = User::factory()->create();
    $colleague = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff);
    $this->tenants->member($staff, $this->tenants->a1, BusinessRole::Staff);
    $this->tenants->member($staff, $this->tenants->b1, BusinessRole::Staff);
    $this->tenants->stamp($this->tenants->enroll(User::factory()->create(), $this->tenants->cardB), $this->tenants->b1, ['staff_id' => $staff->id]);

    app(DeleteAccount::class)->handle($staff);

    $this->context->bypass(function () use ($staff, $colleague): void {
        expect(BusinessMember::query()->where('user_id', $staff->id)->count())->toBe(0)
            ->and(BusinessMember::query()->where('user_id', $colleague->id)->count())->toBe(1);
    });
});

it('refuses an owner who is also staff elsewhere, and removes nothing', function (): void {
    $user = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $this->tenants->member($user, $this->tenants->b1, BusinessRole::Staff);

    expect(fn () => app(DeleteAccount::class)->handle($user))->toThrow(LogicException::class)
        ->and($this->context->bypass(fn (): int => BusinessMember::query()->where('user_id', $user->id)->count()))->toBe(2);
});

it('lets someone who never verified their email delete their account', function (): void {
    $user = User::factory()->unverified()->create();

    ($this->deleteAccount)($user)->assertSessionHasNoErrors()->assertRedirect(route('home'));

    expect($user->fresh())->toBeNull();
});

it('never authenticates an anonymised account, whatever looks it up', function (): void {
    $user = User::factory()->create();
    $this->tenants->stamp($this->tenants->enroll($user, $this->tenants->cardA), $this->tenants->a1);
    app(DeleteAccount::class)->handle($user);
    $provider = auth()->createUserProvider('users');

    expect($provider?->retrieveById($user->id))->toBeNull()
        ->and($provider?->retrieveByCredentials(['email' => $user->fresh()?->email]))->toBeNull()
        ->and($provider?->retrieveById(User::factory()->create()->id))->not->toBeNull();
});

it('keeps anonymised accounts out of user fan-outs and mail', function (): void {
    $user = User::factory()->create();
    $this->tenants->stamp($this->tenants->enroll($user, $this->tenants->cardA), $this->tenants->a1);

    app(DeleteAccount::class)->handle($user);
    $anonymised = $user->fresh();

    expect(User::query()->notAnonymised()->whereKey($user->id)->exists())->toBeFalse()
        ->and($anonymised?->routeNotificationForMail())->toBeNull();
});

it('scrubs the IP and user agent from the account\'s taps, deleted or anonymised', function (bool $withHistory): void {
    $user = User::factory()->create();

    if ($withHistory) {
        $this->tenants->stamp($this->tenants->enroll($user, $this->tenants->cardA), $this->tenants->a1);
    }

    $tap = $this->context->bypass(function () use ($user): Tap {
        $tap = (new Tap)->forceFill(['user_id' => $user->id, 'status' => TapStatus::Rejected, 'rejection' => TapRejection::Replay, 'ip' => '203.0.113.7', 'user_agent' => 'Test phone']);
        $tap->save();

        return $tap;
    });

    ($this->deleteAccount)($user)->assertSessionHasNoErrors();

    expect($this->context->bypass(fn (): ?array => Tap::query()->whereKey($tap->id)->first()?->only(['ip', 'user_agent'])))->toBe(['ip' => null, 'user_agent' => null]);
})->with(['without history' => [false], 'with history' => [true]]);
