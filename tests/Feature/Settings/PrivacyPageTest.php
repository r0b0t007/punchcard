<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The Privacy settings page (CHW-58, C12, review 7a)
|--------------------------------------------------------------------------
|
| Beside Profile, Security and Appearance: punchcard's news (on since, off
| since, or never chosen), for every signed-in user, verified or not (an
| unverified one can still turn news off), in the customer layout for a
| customer.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-18 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows news on, with the day it was turned on', function (): void {
    $user = User::factory()->create(['marketing_opt_in_at' => '2026-10-03 08:00:00']);

    $this->actingAs($user)->get(route('privacy.edit'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('settings/privacy')
            ->where('marketing', ['on' => true, 'since' => '2026-10-03', 'mustVerify' => false])
            ->where('customer', true));
});

it('shows news off, with the day it was turned off, or never chosen', function (?string $optedOut, ?string $since): void {
    $user = User::factory()->create(['marketing_opt_in_at' => null, 'marketing_opt_out_at' => $optedOut]);

    $this->actingAs($user)->get(route('privacy.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('marketing', ['on' => false, 'since' => $since, 'mustVerify' => false]));
})->with([
    'turned off' => ['2026-10-14 17:00:00', '2026-10-14'],
    'never chosen' => [null, null],
]);

it('opens for an unverified email, which must be confirmed to turn news on', function (): void {
    $user = User::factory()->unverified()->create(['marketing_opt_in_at' => null]);

    $this->actingAs($user)->get(route('privacy.edit'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('marketing.mustVerify', true));
});

it('asks a guest to sign in', function (): void {
    $this->get(route('privacy.edit'))->assertRedirect(route('login'));
});
