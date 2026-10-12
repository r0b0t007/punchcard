<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;

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
