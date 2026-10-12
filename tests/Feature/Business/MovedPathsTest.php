<?php

declare(strict_types=1);

use App\Actions\Tenancy\CreateIndependentBusiness;
use App\Enums\BusinessRole;
use App\Http\Middleware\SetTenant;
use App\Models\User;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The business app lives under /business (CHW-151)
|--------------------------------------------------------------------------
|
| Every page of punchcard Pro sits under /business, the scope of its
| manifest, so a tap (/t) or a join link (/j) never opens in it, and its
| sign-in page too, so opening the app signed out stays in it. The old
| addresses answer with a permanent redirect, query string, method and
| form data kept.
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

it('signs a guest in without leaving the app', function (): void {
    $this->get('/business')->assertRedirect('/business/login');
    $this->get('/business/cards')->assertRedirect('/business/login');

    $this->get('/business/login')->assertOk()
        ->assertSee('<link rel="manifest" href="/manifest-business.webmanifest" data-pwa>', escape: false)
        ->assertInertia(fn ($page) => $page->component('auth/login'));

    // The form posts to Fortify's /login, then back to the page the guest asked for last.
    $this->post(route('login.store'), ['email' => $this->owner->email, 'password' => 'password'])
        ->assertRedirect('/business/cards');
});

it('keeps sending a guest of the customer\'s pages to the usual sign-in', function (string $path): void {
    $this->get($path)->assertRedirect(route('login'));
})->with(['/app', '/app/cards/1', '/rewards']);

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

it('sends a page loaded before the move on with its saves, method and form kept', function (string $method, string $old, string $new): void {
    $this->call($method, $old, ['name' => 'Kept'])
        ->assertStatus(308)
        ->assertRedirect($new);
})->with([
    'a new card' => ['POST', '/cards', '/business/cards'],
    'a card saved' => ['PUT', '/cards/5', '/business/cards/5'],
    'a card paused' => ['PATCH', '/cards/5/status', '/business/cards/5/status'],
    'a wizard step saved' => ['PUT', '/onboarding/location', '/business/onboarding/location'],
]);

it('keeps unknown addresses unknown', function (string $path): void {
    $this->get($path)->assertNotFound();
})->with(['/dashboard/extra', '/cardsx', '/onboardingx']);

it('keeps everything under /business for punchcard Pro', function (): void {
    $outsideTheApp = ['business.register', 'business.register.store'];

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route): bool => str_starts_with($route->uri(), 'business'));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        // A /businesses… page would fall in punchcard Pro's scope (/business) too.
        expect($route->uri() === 'business' || str_starts_with($route->uri(), 'business/'))->toBeTrue($route->uri())
            ->and(in_array('pwa:business', $route->gatherMiddleware(), true) || in_array($route->getName(), $outsideTheApp, true))
            ->toBeTrue("{$route->uri()} is in punchcard Pro's scope but not its page");
    }
});
