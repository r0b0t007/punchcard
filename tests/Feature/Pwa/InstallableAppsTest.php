<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Http\Middleware\SetTenant;
use App\Http\Middleware\UsePwa;
use App\Models\User;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The installable apps (CHW-35, pwa-conventions skill)
|--------------------------------------------------------------------------
|
| A customer's pages belong to punchcard, a business's to punchcard Pro:
| each links its own manifest and registers the service worker. The
| marketing site, sign-in and settings belong to neither.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
});

it('links the customer app on a customer\'s pages', function (): void {
    $this->actingAs(User::factory()->create())->get(route('app.cards'))
        ->assertSee('<link rel="manifest" href="/manifest-customer.webmanifest">', escape: false)
        ->assertSee('<meta name="apple-mobile-web-app-title" content="punchcard">', escape: false)
        ->assertInertia(fn ($page) => $page->where('pwa', 'customer'));
});

it('links the business app on a business\'s pages', function (): void {
    $owner = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner);

    $this->actingAs($owner)->withSession([SetTenant::SESSION_KEY => 'business:'.$this->tenants->b1->id])->get(route('dashboard'))
        ->assertSee('<link rel="manifest" href="/manifest-business.webmanifest">', escape: false)
        ->assertSee('<meta name="apple-mobile-web-app-title" content="punchcard Pro">', escape: false)
        ->assertInertia(fn ($page) => $page->where('pwa', 'business'));
});

it('links no app on the marketing site or the sign-in page', function (string $path): void {
    $this->get($path)->assertOk()
        ->assertDontSee('rel="manifest"', escape: false)
        ->assertInertia(fn ($page) => $page->where('pwa', null));
})->with(['/', '/login']);

it('describes both apps in valid manifests whose icons exist', function (string $app, string $startUrl): void {
    $manifest = json_decode((string) file_get_contents(public_path("manifest-{$app}.webmanifest")), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)->toHaveKeys(['id', 'name', 'short_name', 'start_url', 'scope', 'display', 'theme_color', 'background_color', 'icons'])
        ->and($manifest['start_url'])->toBe($startUrl)
        ->and($manifest['scope'])->toBe('/')
        ->and(array_column($manifest['icons'], 'sizes'))->toContain('192x192', '512x512')
        ->and(array_column($manifest['icons'], 'purpose'))->toContain('maskable');

    foreach ($manifest['icons'] as $icon) {
        expect(public_path(ltrim($icon['src'], '/')))->toBeFile();
    }
})->with([
    'customer' => ['customer', '/app'],
    'business' => ['business', '/dashboard'],
]);

it('knows no app but its two', function (): void {
    expect(fn (): Response => (new UsePwa)->handle(Request::create('/'), fn (): ResponseFactory|\Illuminate\Http\Response => response('ok'), 'admin'))
        ->toThrow(InvalidArgumentException::class);
});
