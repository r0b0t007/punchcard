<?php

declare(strict_types=1);

use App\Models\LoginLink;
use App\Models\User;
use App\Notifications\ConfirmLinkMail;
use App\Notifications\ConfirmNewEmailMail;
use App\Notifications\EmailChangedMail;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Settings without a password (CHW-23)
|--------------------------------------------------------------------------
|
| A customer who signed up by email link has no password. Where settings
| ask "is it you?" (security, deleting the account), they confirm with a
| link mailed to their own address, or a passkey: never a password they
| don't have. They can then choose one, and delete their account.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15 10:00:00');
    app()->setLocale('en');
    $this->customer = User::factory()->create(['email' => 'salma@example.test', 'password' => null]);

    // The last confirm link mailed to an account.
    $this->confirmLinkFor = function (User $user): string {
        $urls = new ArrayObject;
        Notification::assertSentTo($user, ConfirmLinkMail::class, function (ConfirmLinkMail $mail) use ($urls): bool {
            $urls[] = $mail->url;

            return true;
        });

        return (string) $urls[count($urls) - 1];
    };
    $this->confirmed = fn (): bool => session('auth.password_confirmed_at') === now()->unix();

    // The last link mailed to a new address, to switch to it.
    $this->newAddressLink = function (string $address): string {
        $urls = new ArrayObject;
        Notification::assertSentOnDemand(ConfirmNewEmailMail::class, function (ConfirmNewEmailMail $mail, array $channels, AnonymousNotifiable $to) use ($address, $urls): bool {
            if ($to->routes['mail'] === $address) {
                $urls[] = $mail->url;
            }

            return $to->routes['mail'] === $address;
        });

        return (string) $urls[count($urls) - 1];
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('offers an account without a password to confirm by email, and one with a password its password', function (): void {
    $this->actingAs($this->customer)->get(route('password.confirm'))
        ->assertInertia(fn (Assert $page): Assert => $page->component('auth/confirm-password')->where('hasPassword', false)->where('mayConfirmByEmail', true));

    $this->actingAs(User::factory()->create())->get(route('password.confirm'))
        ->assertInertia(fn (Assert $page): Assert => $page->component('auth/confirm-password')->where('hasPassword', true)->where('mayConfirmByEmail', false));

    // Without a password, nor a link to confirm by (two-factor on): the page says what to do instead.
    $this->actingAs(User::factory()->withTwoFactor()->create(['password' => null]))->get(route('password.confirm'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('hasPassword', false)->where('mayConfirmByEmail', false));
});

it('mails a link to the account\'s own address, whose button confirms it\'s them and goes back where they were', function (): void {
    Notification::fake();

    $this->actingAs($this->customer)->withSession(['url.intended' => route('security.edit')])
        ->post(route('password.confirm-by-email'))
        ->assertRedirect()->assertSessionHas('status', 'confirm-link-sent');
    $link = ($this->confirmLinkFor)($this->customer);

    // Opening it only shows its button.
    $this->get($link)->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('auth/confirm-link-open')->where('state', 'ready'));
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();

    $this->post($link)->assertRedirect(route('security.edit'));

    expect(($this->confirmed)())->toBeTrue();
});

it('refuses a confirm link opened by another account, or used twice', function (): void {
    Notification::fake();
    $this->actingAs($this->customer)->post(route('password.confirm-by-email'));
    $link = ($this->confirmLinkFor)($this->customer);

    $someoneElse = User::factory()->create(['password' => null]);
    $this->actingAs($someoneElse)->get($link)
        ->assertInertia(fn (Assert $page): Assert => $page->where('state', 'other-account'));
    $this->actingAs($someoneElse)->post($link)
        ->assertInertia(fn (Assert $page): Assert => $page->where('state', 'other-account'));
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();

    $this->actingAs($this->customer)->post($link)->assertRedirect();
    $this->post($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));
});

it('never lets a confirm link sign anyone in, nor a sign-in link confirm', function (): void {
    Notification::fake();
    $this->actingAs($this->customer)->post(route('password.confirm-by-email'));
    $confirmLink = LoginLink::query()->sole();
    $signInLink = LoginLink::for('salma@example.test');

    // A sign-in link, signed as a confirm link: refused.
    $asConfirm = url(URL::temporarySignedRoute('password.confirm-by-email.show', now()->addMinutes(15), ['link' => $signInLink->id], absolute: false));
    $this->post($asConfirm)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();

    // A confirm link, signed as a sign-in link, opened signed out: refused.
    auth()->logout();
    $asSignIn = url(URL::temporarySignedRoute('login-link.show', now()->addMinutes(15), ['link' => $confirmLink->id], absolute: false));
    $this->post($asSignIn)->assertInertia(fn (Assert $page): Assert => $page->where('expired', true));
    $this->assertGuest();
});

it('refuses a confirm link past its time', function (): void {
    Notification::fake();
    $this->actingAs($this->customer)->post(route('password.confirm-by-email'));
    $link = ($this->confirmLinkFor)($this->customer);
    $this->travel(16)->minutes();

    $this->post($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));
});

it('limits confirm links per account', function (): void {
    Notification::fake();

    foreach (range(1, 3) as $attempt) {
        $this->actingAs($this->customer)->post(route('password.confirm-by-email'))->assertSessionHas('status', 'confirm-link-sent');
    }

    $this->actingAs($this->customer)->post(route('password.confirm-by-email'))->assertSessionHasErrors('email');
    expect(LoginLink::query()->count())->toBe(3);
});

it('asks an account without a password to confirm before deleting it, then deletes it', function (): void {
    $this->actingAs($this->customer)->delete(route('profile.destroy'))->assertSessionHasErrors('password');
    expect($this->customer->fresh())->not->toBeNull();

    $this->actingAs($this->customer)->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->delete(route('profile.destroy'))->assertSessionHasNoErrors()->assertRedirect('/');

    expect($this->customer->fresh())->toBeNull();
});

it('leads from the delete dialog to confirming, then back to the profile', function (): void {
    $this->actingAs($this->customer)->get(route('profile.confirm'))->assertRedirect(route('password.confirm'));

    $this->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->get(route('profile.confirm'))->assertRedirect(route('profile.edit'));
});

it('lets an account without a password choose one, with no current password to give', function (): void {
    $this->actingAs($this->customer)->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->put(route('user-password.update'), ['password' => 'a-long-test-password-1', 'password_confirmation' => 'a-long-test-password-1'])
        ->assertSessionHasNoErrors();

    expect(Hash::check('a-long-test-password-1', (string) $this->customer->fresh()->password))->toBeTrue();
});

it('still asks an account with a password for it, to change it', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->put(route('user-password.update'), ['password' => 'a-long-test-password-1', 'password_confirmation' => 'a-long-test-password-1'])
        ->assertSessionHasErrors('current_password');
});

it('says on the profile and security pages whether the account has a password', function (): void {
    $this->actingAs($this->customer)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('hasPassword', false));
    $this->withSession(['auth.password_confirmed_at' => now()->unix()])->get(route('security.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('hasPassword', false));
});

it('keeps the old address until the new one is confirmed from its own inbox, then tells the old one', function (): void {
    Notification::fake();

    $this->actingAs($this->customer)->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->patch(route('profile.update'), ['name' => $this->customer->name, 'email' => 'Salma.New@Example.test'])
        ->assertSessionHasNoErrors();

    $customer = $this->customer->fresh();
    expect($customer->email)->toBe('salma@example.test')
        ->and($customer->hasVerifiedEmail())->toBeTrue()
        ->and($customer->pending_email)->toBe('salma.new@example.test');
    Notification::assertNotSentTo($customer, VerifyEmail::class);
    Notification::assertSentOnDemandTimes(EmailChangedMail::class, 0);
    $this->actingAs($customer)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('pendingEmail', 'salma.new@example.test'));

    // From the new inbox, signed in or not.
    auth()->logout();
    $link = ($this->newAddressLink)('salma.new@example.test');
    $this->get($link)->assertInertia(fn (Assert $page): Assert => $page->component('auth/email-change-open')->where('state', 'ready'));
    expect($customer->fresh()->email)->toBe('salma@example.test');

    $this->post($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'changed'));

    $customer = $customer->fresh();
    expect($customer->email)->toBe('salma.new@example.test')
        ->and($customer->hasVerifiedEmail())->toBeTrue()
        ->and($customer->pending_email)->toBeNull();
    Notification::assertSentOnDemand(EmailChangedMail::class, fn (EmailChangedMail $mail, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'salma@example.test');

    // Once.
    $this->post($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));
});

it('costs nothing to mistype a new address: the old one still signs in and confirms', function (): void {
    Notification::fake();
    $this->actingAs($this->customer)->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->patch(route('profile.update'), ['name' => $this->customer->name, 'email' => 'salma@gmial.test']);
    $this->travel(4)->hours();

    $this->actingAs($this->customer->fresh())->post(route('password.confirm-by-email'))->assertSessionHas('status', 'confirm-link-sent');

    Notification::assertSentTo($this->customer, ConfirmLinkMail::class);
});

it('never switches to an address someone else took meanwhile', function (): void {
    Notification::fake();
    $this->actingAs($this->customer)->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->patch(route('profile.update'), ['name' => $this->customer->name, 'email' => 'salma.new@example.test']);
    $link = ($this->newAddressLink)('salma.new@example.test');
    User::factory()->create(['email' => 'salma.new@example.test']);

    $this->post($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'taken'));

    expect($this->customer->fresh()->email)->toBe('salma@example.test');
});

it('switches only to the address last asked for', function (): void {
    Notification::fake();
    $session = ['auth.password_confirmed_at' => now()->unix()];
    $this->actingAs($this->customer)->withSession($session)->patch(route('profile.update'), ['name' => $this->customer->name, 'email' => 'first@example.test']);
    $first = ($this->newAddressLink)('first@example.test');
    $this->actingAs($this->customer->fresh())->withSession($session)->patch(route('profile.update'), ['name' => $this->customer->name, 'email' => 'second@example.test']);

    $this->post($first)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));

    expect($this->customer->fresh()->email)->toBe('salma@example.test');
});

it('limits the new addresses mailed, never the name', function (): void {
    Notification::fake();
    $session = ['auth.password_confirmed_at' => now()->unix()];
    $save = fn (array $profile) => $this->actingAs($this->customer)->withSession($session)->patch(route('profile.update'), $profile);

    foreach (range(1, 5) as $attempt) {
        $save(['name' => $this->customer->name, 'email' => "new{$attempt}@example.test"])->assertSessionHasNoErrors();
    }

    // A sixth new address: a form error, not an error page, and nothing saved, the name neither.
    $save(['name' => 'Salma Renamed', 'email' => 'new6@example.test'])->assertSessionHasErrors('email');
    expect($this->customer->fresh())->pending_email->toBe('new5@example.test')->name->not->toBe('Salma Renamed');

    // The name, as often as they like.
    foreach (range(1, 6) as $attempt) {
        $save(['name' => "Salma {$attempt}", 'email' => 'salma@example.test'])->assertSessionHasNoErrors();
    }
});

it('keeps a new address waiting when only the name is saved (the form sends the current address too)', function (): void {
    Notification::fake();
    $session = ['auth.password_confirmed_at' => now()->unix()];
    $this->actingAs($this->customer)->withSession($session)->patch(route('profile.update'), ['name' => $this->customer->name, 'email' => 'salma.new@example.test']);

    $this->actingAs($this->customer->fresh())->withSession($session)
        ->patch(route('profile.update'), ['name' => 'Salma B.', 'email' => 'salma@example.test'])
        ->assertSessionHasNoErrors();

    expect($this->customer->fresh())->name->toBe('Salma B.')->pending_email->toBe('salma.new@example.test');
    $this->post(($this->newAddressLink)('salma.new@example.test'))->assertInertia(fn (Assert $page): Assert => $page->where('state', 'changed'));
});

it('cancels a new address from settings: its link stops working', function (): void {
    Notification::fake();
    $this->actingAs($this->customer)->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->patch(route('profile.update'), ['name' => $this->customer->name, 'email' => 'salma@gmial.test']);
    $link = ($this->newAddressLink)('salma@gmial.test');

    $this->actingAs($this->customer->fresh())->delete(route('email-change.cancel'))->assertRedirect(route('profile.edit'));

    expect($this->customer->fresh()->pending_email)->toBeNull();
    $this->get($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired')->where('action', null));
    $this->post($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));
    expect($this->customer->fresh()->email)->toBe('salma@example.test');
});

it('stops the sign-in links and reset tokens mailed to the old address once it changed', function (): void {
    Notification::fake();
    $oldSignInLink = LoginLink::for('salma@example.test');
    DB::table('password_reset_tokens')->insert(['email' => 'salma@example.test', 'token' => Hash::make('reset'), 'created_at' => now()]);
    $this->actingAs($this->customer)->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->patch(route('profile.update'), ['name' => $this->customer->name, 'email' => 'salma.new@example.test']);
    $this->post(($this->newAddressLink)('salma.new@example.test'))->assertInertia(fn (Assert $page): Assert => $page->where('state', 'changed'));
    auth()->logout();

    // Opened afterwards, it would sign up a second account on the old address.
    $this->post($oldSignInLink->signedUrl())->assertInertia(fn (Assert $page): Assert => $page->component('auth/email-link-open')->where('expired', true));
    $this->assertGuest();
    expect(DB::table('password_reset_tokens')->where('email', 'salma@example.test')->exists())->toBeFalse();
});

it('stops showing a new address once its link is out of date', function (): void {
    Notification::fake();
    $this->actingAs($this->customer)->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->patch(route('profile.update'), ['name' => $this->customer->name, 'email' => 'salma.new@example.test']);
    $this->travel(61)->minutes();

    $this->actingAs($this->customer->fresh())->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('pendingEmail', null));
});

it('never puts an address back on a deleted account', function (): void {
    Notification::fake();
    $this->actingAs($this->customer)->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->patch(route('profile.update'), ['name' => $this->customer->name, 'email' => 'salma.new@example.test']);
    $link = ($this->newAddressLink)('salma.new@example.test');
    // Anonymised (deleted with history kept) after asking for it, as DeleteAccount does, but for the pending address.
    $this->customer->fresh()->forceFill(['email' => 'deleted-x@deleted.invalid', 'anonymised_at' => now()])->save();
    auth()->logout();

    $this->post($link)->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));

    expect($this->customer->fresh()->email)->toBe('deleted-x@deleted.invalid');
});

it('says whose a confirm link is only once its signature holds', function (): void {
    Notification::fake();
    $this->actingAs($this->customer)->post(route('password.confirm-by-email'));
    $theirs = LoginLink::query()->sole();

    // Unsigned, from another account: nothing about whose it is.
    $this->actingAs(User::factory()->create(['password' => null]))
        ->post(route('password.confirm-by-email.confirm', $theirs->id))
        ->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));
});

it('changes no address without a recent confirmation (a stale session can\'t move the account)', function (): void {
    $user = User::factory()->create(['email' => 'nadia@example.test']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Nadia', 'email' => 'attacker@example.test'])
        ->assertSessionHasErrors('email');
    expect($user->fresh()->email)->toBe('nadia@example.test');

    // The name alone needs none.
    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Nadia T.', 'email' => 'nadia@example.test'])->assertSessionHasNoErrors();
});

it('confirms by email only an account a link could sign in anyway (no password, confirmed, no second factor)', function (string $account): void {
    Notification::fake();
    $user = match ($account) {
        'with a password' => User::factory()->create(),
        'with two-factor' => User::factory()->withTwoFactor()->create(['password' => null]),
        'unconfirmed address' => User::factory()->unverified()->create(['password' => null]),
    };

    $this->actingAs($user)->post(route('password.confirm-by-email'))->assertSessionHasErrors('email');
    Notification::assertNothingSent();

    // Nor does a confirm link for it count.
    $link = LoginLink::for($user->email, LoginLink::CONFIRM);
    $this->post($link->signedUrl())->assertInertia(fn (Assert $page): Assert => $page->where('state', 'expired'));
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
})->with(['with a password', 'with two-factor', 'unconfirmed address']);

it('never confirms with a link mailed to the address before it changed', function (): void {
    Notification::fake();
    $this->actingAs($this->customer)->post(route('password.confirm-by-email'));
    $link = ($this->confirmLinkFor)($this->customer);
    $this->customer->forceFill(['email' => 'salma.new@example.test'])->save();

    $this->post($link)->assertInertia(fn (Assert $page): Assert => $page->where('action', null));
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
});

it('keeps this phone signed in for the year after a first password, and signs other devices out', function (): void {
    // Sessions in the database, as the app keeps them (config/session.php): the other phone's is a row.
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert(['id' => 'their-other-phone', 'user_id' => $this->customer->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
    $recaller = auth()->guard()->getRecallerName();

    $response = $this->actingAs($this->customer)
        ->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->withCookie($recaller, $this->customer->id.'|old-token|')
        ->put(route('user-password.update'), ['password' => 'a-long-test-password-1', 'password_confirmation' => 'a-long-test-password-1'])
        ->assertSessionHasNoErrors();

    // A new remember cookie, made for the new password.
    $remember = collect($response->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === $recaller);
    expect($remember)->not->toBeNull()
        ->and(DB::table('sessions')->where('id', 'their-other-phone')->exists())->toBeFalse();
});

it('asks an account without a password to confirm before choosing one', function (): void {
    $this->actingAs($this->customer)
        ->put(route('user-password.update'), ['password' => 'a-long-test-password-1', 'password_confirmation' => 'a-long-test-password-1'])
        ->assertSessionHasErrors('password');

    expect($this->customer->fresh()->password)->toBeNull();
});
