<?php

declare(strict_types=1);

use App\Actions\Tenancy\CreateIndependentBusiness;
use App\Enums\BusinessRole;
use App\Http\Middleware\SetTenant;
use App\Models\User;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The business app lives under /business (CHW-151)
|--------------------------------------------------------------------------
|
| Every page of punchcard Pro sits under /business, the scope of its
| manifest, so a tap (/t) or a join link (/j) never opens in it. The old
| addresses answer with a permanent redirect, query string and all.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner);
    $this->inB1 = fn () => $this->actingAs($this->owner)->withSession([SetTenant::SESSION_KEY => 'business:'.$this->tenants->b1->id]);
});

it('serves the business pages under /business', function (): void {
    $card = $this->tenants->cardB;

    expect(route('dashboard', absolute: false))->toBe('/business')
        ->and(route('cards.index', absolute: false))->toBe('/business/cards')
        ->and(route('cards.create', absolute: false))->toBe('/business/cards/create')
        ->and(route('cards.edit', $card, absolute: false))->toBe("/business/cards/{$card->id}/edit")
        ->and(route('business.qr-stand', absolute: false))->toBe('/business/qr-stand')
        ->and(route('onboarding.show', absolute: false))->toBe('/business/onboarding');

    foreach (['/business', '/business/cards', '/business/cards/create', "/business/cards/{$card->id}/edit"] as $path) {
        ($this->inB1)()->get($path)->assertOk()
            ->assertSee('<link rel="manifest" href="/manifest-business.webmanifest" data-pwa>', escape: false);
    }
});

it('puts the onboarding wizard under /business too', function (): void {
    $starter = User::factory()->create();
    app(CreateIndependentBusiness::class)->handle($starter, 'Café Nour');

    $this->actingAs($starter)->get(route('onboarding.show'))->assertRedirect('/business/onboarding/business');
    $this->actingAs($starter)->get('/business/onboarding/business')->assertOk()
        ->assertSee('<link rel="manifest" href="/manifest-business.webmanifest" data-pwa>', escape: false);
});

it('sends the old addresses to the new ones for good, with their query string', function (string $old, string $new): void {
    $card = $this->tenants->cardB;

    $this->get(str_replace('{card}', (string) $card->id, $old))
        ->assertStatus(301)
        ->assertRedirect(str_replace('{card}', (string) $card->id, $new));
})->with([
    'the dashboard' => ['/dashboard?location=4', '/business?location=4'],
    'the cards' => ['/cards', '/business/cards'],
    'a new card' => ['/cards/create', '/business/cards/create'],
    'the card builder' => ['/cards/{card}/edit', '/business/cards/{card}/edit'],
    'the wizard' => ['/onboarding', '/business/onboarding'],
    'a wizard step' => ['/onboarding/location', '/business/onboarding/location'],
]);

it('leaves the customer\'s addresses where they are', function (string $path): void {
    $this->get($path)->assertStatus(302)->assertRedirect(route('login'));
})->with(['/app', '/app/cards/1', '/rewards']);

it('keeps unknown addresses unknown', function (string $path): void {
    $this->get($path)->assertNotFound();
})->with(['/cards/abc/edit', '/dashboard/extra', '/onboarding/a/b']);
