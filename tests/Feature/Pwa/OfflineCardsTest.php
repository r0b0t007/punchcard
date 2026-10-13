<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\Locales;

/*
|--------------------------------------------------------------------------
| The customer's cards kept on the phone (CHW-35, C5)
|--------------------------------------------------------------------------
|
| My cards keeps a copy of the customer's cards in the browser for offline
| use (resources/js/lib/offline-cards.ts). What the server owes it: every
| page's props encrypted in the browser's history, so once a sign-out
| clears the key, Back asks the server again instead of showing (and
| saving) the previous customer's cards.
|
*/

it('encrypts My cards in the browser\'s history', function (): void {
    $version = app(HandleInertiaRequests::class)->version(request());

    $this->actingAs(User::factory()->create())
        ->get(route('app.cards'), ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])
        ->assertOk()
        ->assertJsonPath('component', 'app/cards')
        ->assertJsonPath('encryptHistory', true);
});

it('serves the offline page the same to everyone, nothing of the user in it', function (): void {
    $customer = User::factory()->create(['name' => 'Salma Bennani', 'email' => 'salma@example.test']);

    $this->actingAs($customer)->get(route('offline'))
        ->assertOk()
        ->assertSee('<div id="offline"></div>', escape: false)
        ->assertSee('<link rel="manifest" href="/manifest-customer.webmanifest">', escape: false)
        ->assertDontSee('Salma')
        ->assertDontSee('salma@example.test');
});

it('serves the offline page without a session, so the copy kept on phones carries no cookie', function (): void {
    $this->get(route('offline'))
        ->assertOk()
        ->assertCookieMissing((string) config('session.cookie'))
        ->assertCookieMissing('XSRF-TOKEN');
});

it('serves the offline page during maintenance, so a new service worker still installs', function (): void {
    config(['app.maintenance.driver' => 'array']);
    app()->maintenanceMode()->activate(['status' => 503]);

    $this->get('/')->assertServiceUnavailable();
    $this->get(route('offline'))->assertOk();
});

it('gives the offline page its words in every language, for a phone with no cards kept', function (): void {
    $response = $this->get(route('offline'))->assertOk();
    $nothingKept = $response->viewData('nothingKept');

    expect($response->viewData('defaultLocale'))->toBe(Locales::default());

    expect(array_keys($nothingKept))->toBe(Locales::codes())
        ->and($nothingKept['ar']['dir'])->toBe('rtl')
        ->and($nothingKept['ar']['strings']["You're offline"])->toBe('أنت غير متصل')
        ->and($nothingKept['fr']['strings']['Try again'])->toBe('Réessayer')
        ->and($nothingKept['en']['strings'])->toBe([
            "You're offline" => "You're offline",
            'Your cards show here once you are back online.' => 'Your cards show here once you are back online.',
            'Try again' => 'Try again',
        ]);
});
