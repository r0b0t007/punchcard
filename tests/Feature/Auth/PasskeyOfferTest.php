<?php

declare(strict_types=1);

use App\Models\LoginLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The passkey offer after an email-link sign-in (CHW-23)
|--------------------------------------------------------------------------
|
| A customer just signed in by email link is offered a passkey on their
| screens: next time, Face ID or a fingerprint. While the session counts
| as confirmed (adding one needs it), until they add one or say "not now".
| Never after a password sign-in.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15 10:00:00');
    app()->setLocale('en');
    $this->customer = User::factory()->create(['email' => 'salma@example.test', 'password' => null]);
    $this->signInByLink = fn () => $this->post(LoginLink::for('salma@example.test')->signedUrl());
    $this->offered = fn (bool $offered) => $this->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('offerPasskey', $offered));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('offers a passkey after an email-link sign-in, until "not now"', function (): void {
    ($this->signInByLink)();
    $this->assertAuthenticatedAs($this->customer);

    ($this->offered)(true);

    $this->post(route('passkey-offer.dismiss'))->assertRedirect();
    ($this->offered)(false);
});

it('stops offering once the account has a passkey, for the rest of the session', function (): void {
    ($this->signInByLink)();
    $passkey = $this->customer->passkeys()->create(['name' => 'iPhone', 'credential_id' => 'cred-1', 'credential' => []]);

    ($this->offered)(false);

    // The offer ended there: no passkey lookup on every page from then on.
    $passkey->delete();
    ($this->offered)(false);
});

it('stops offering once adding one would ask to confirm it\'s them again', function (): void {
    ($this->signInByLink)();
    $this->travel(config('auth.password_timeout') + 1)->seconds();

    ($this->offered)(false);

    // Ended for the session, even once confirmed again.
    $this->withSession(['auth.password_confirmed_at' => now()->unix()]);
    ($this->offered)(false);
});

it('never offers one after a password sign-in', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);

    ($this->offered)(false);
});
