<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Consent on every sign-up form (CHW-23)
|--------------------------------------------------------------------------
|
| Every new account says it is 16 or older (no account otherwise) and
| whether it wants news by email, each dated (CNDP), whichever form made
| it: the email link's (FinishSignUpTest), a customer's password sign-up,
| or a business owner's. News only when ticked.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15 10:00:00');
    app()->setLocale('en');
    $this->signUp = fn (string $form, array $consent) => $this->post(
        $form === 'customer' ? route('register.store') : route('business.register.store'),
        ['name' => 'Salma Tapper', 'email' => 'salma@example.test', 'password' => 'password', 'password_confirmation' => 'password', 'business_name' => 'Café Salma', ...$consent],
    );
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('creates no account without "16 or older", and says what is missing', function (string $form): void {
    ($this->signUp)($form, [])->assertSessionHasErrors(['adult' => "Confirm you're 16 or older to create an account."]);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
})->with(['customer', 'business']);

it('dates "16 or older", and news only when ticked', function (string $form, array $consent, bool $wantsNews): void {
    ($this->signUp)($form, $consent)->assertSessionHasNoErrors();

    $this->assertAuthenticated();
    expect(User::query()->sole())
        ->adult_confirmed_at->toEqual(now())
        ->marketing_opt_in_at->toEqual($wantsNews ? now() : null);
})->with(['customer', 'business'])->with([
    'news ticked' => [['adult' => '1', 'marketing' => '1'], true],
    'news left unticked' => [['adult' => '1'], false],
]);
