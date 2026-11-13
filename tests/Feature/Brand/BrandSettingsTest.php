<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Http\Middleware\SetTenant;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Models\User;
use App\Support\Branding\Contrast;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The brand page (CHW-135, B17, design 24)
|--------------------------------------------------------------------------
|
| Whoever acts for the organization changes its logo, brand colour and
| cover: an independent café's owner, a franchise's head office at network
| level. A franchisee, staff, or another organization never can, and nothing
| is written when they try.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner);
    $this->hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $this->as = fn (User $user, string $tenant) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => $tenant]);
    $this->inB1 = fn () => ($this->as)($this->owner, 'business:'.$this->tenants->b1->id);
    $this->atHq = fn () => ($this->as)($this->hq, 'org:'.$this->tenants->orgA->id);
    $this->organization = fn (Organization $organization): Organization => $this->context->bypass(fn (): Organization => Organization::query()->findOrFail($organization->id));
});

it('shows an independent café\'s owner its brand, named as its customers see it, with a sample card', function (): void {
    $this->context->bypass(fn () => $this->tenants->orgB->forceFill(['brand_color' => '#0F766E'])->save());

    ($this->inB1)()->get(route('brand.edit'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('business/brand')
        ->where('brand.name', $this->tenants->b1->name)
        ->where('brand.franchise', false)
        ->where('brand.brandColor', '#0F766E')
        ->where('brand.brandForeground', Contrast::readableForeground('#0F766E'))
        ->where('brand.coverUrl', null)
        ->where('sample.cardName', $this->tenants->cardB->name)
        ->where('sites', null));
});

it('shows a franchise\'s head office its brand and how many sites show it: neither closed nor suspended', function (): void {
    $sites = fn (): int => ($this->atHq)()->get(route('brand.edit'))->assertOk()->viewData('page')['props']['sites'];

    ($this->atHq)()->get(route('brand.edit'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('brand.name', $this->tenants->orgA->name)
        ->where('brand.franchise', true)
        ->where('brand.brandForeground', null)
        ->where('sites', 2));

    $this->context->bypass(fn () => $this->tenants->a2->forceFill(['archived_at' => now()])->save());
    expect($sites())->toBe(1);

    $this->context->bypass(fn () => $this->tenants->a1->forceFill(['status' => BusinessStatus::Suspended])->save());
    expect($sites())->toBe(0);
});

it('draws the previews with a plain card when the program has no active card', function (): void {
    $this->context->bypass(fn () => LoyaltyCard::query()->whereKey($this->tenants->cardB->id)->update(['active' => false]));

    ($this->inB1)()->get(route('brand.edit'))->assertInertia(fn (Assert $page): Assert => $page->where('sample', null));
});

it('saves the brand colour upper-cased, and clears it', function (): void {
    ($this->inB1)()->patch(route('brand.update'), ['brand_color' => '#0f4c81'])->assertRedirect(route('brand.edit'));
    expect(($this->organization)($this->tenants->orgB)->brand_color)->toBe('#0F4C81');

    ($this->inB1)()->patch(route('brand.update'), ['brand_color' => null])->assertRedirect(route('brand.edit'));
    expect(($this->organization)($this->tenants->orgB)->brand_color)->toBeNull();
});

it('refuses a colour that is not #RRGGBB', function (string $color): void {
    ($this->inB1)()->patch(route('brand.update'), ['brand_color' => $color])->assertSessionHasErrors('brand_color');

    expect(($this->organization)($this->tenants->orgB)->brand_color)->toBeNull();
})->with(['#abc', 'teal', '#0F4C81; x', '0F4C81']);

it('lets a franchise\'s head office change its brand, never a franchisee, staff or head office inside one site', function (): void {
    $franchisee = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    // Head office also runs A1 itself: working there, it acts for A1, not for the organization.
    $hqInA1 = $this->tenants->member($this->tenants->admin(User::factory()->create(), $this->tenants->orgA), $this->tenants->a1, BusinessRole::Owner);
    $tries = fn (User $user, string $tenant): array => [
        ($this->as)($user, $tenant)->get(route('brand.edit')),
        ($this->as)($user, $tenant)->patch(route('brand.update'), ['brand_color' => '#B4372B']),
        ($this->as)($user, $tenant)->post(route('brand.logo'), ['logo' => UploadedFile::fake()->image('l.png', 256, 256)]),
        ($this->as)($user, $tenant)->post(route('brand.cover'), ['cover' => UploadedFile::fake()->image('c.png', 1200, 400)]),
        ($this->as)($user, $tenant)->delete(route('brand.cover.destroy')),
    ];
    Storage::fake('public');

    foreach ([[$franchisee, 'business:'.$this->tenants->a1->id], [$staff, 'business:'.$this->tenants->a1->id], [$hqInA1, 'business:'.$this->tenants->a1->id]] as [$user, $tenant]) {
        foreach ($tries($user, $tenant) as $response) {
            $response->assertForbidden();
        }
    }

    $orgA = ($this->organization)($this->tenants->orgA);
    expect([$orgA->brand_color, $orgA->logo_path, $orgA->cover_path])->toBe([null, null, null])
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    ($this->atHq)()->patch(route('brand.update'), ['brand_color' => '#B4372B'])->assertRedirect(route('brand.edit'));
    expect(($this->organization)($this->tenants->orgA)->brand_color)->toBe('#B4372B');
});

it('only ever changes the organization worked for', function (): void {
    $otherAdmin = $this->tenants->admin(User::factory()->create(), $this->tenants->orgB);

    ($this->as)($otherAdmin, 'org:'.$this->tenants->orgB->id)->patch(route('brand.update'), ['brand_color' => '#2E7D4F'])->assertRedirect();
    ($this->atHq)()->patch(route('brand.update'), ['brand_color' => '#B4372B']);

    expect(($this->organization)($this->tenants->orgB)->brand_color)->toBe('#2E7D4F')
        ->and(($this->organization)($this->tenants->orgA)->brand_color)->toBe('#B4372B');
});

it('stores a logo and a cover under random names, removes the ones they replace, and takes the cover away', function (): void {
    Storage::fake('public');
    $logo = fn (UploadedFile $file) => ($this->inB1)()->post(route('brand.logo'), ['logo' => $file]);
    $cover = fn (UploadedFile $file) => ($this->inB1)()->post(route('brand.cover'), ['cover' => $file]);

    $logo(UploadedFile::fake()->image('my-logo.png', 256, 256))->assertRedirect(route('brand.edit'));
    $cover(UploadedFile::fake()->image('my-cover.jpg', 1200, 400))->assertRedirect(route('brand.edit'));
    $first = ($this->organization)($this->tenants->orgB)->cover_path;
    $cover(UploadedFile::fake()->image('second.jpg', 1200, 400));
    $orgB = ($this->organization)($this->tenants->orgB);

    expect($orgB->logo_path)->toStartWith('logos/'.$this->tenants->orgB->id.'/')->not->toContain('my-logo')
        ->and($first)->toStartWith('covers/'.$this->tenants->orgB->id.'/')->not->toContain('my-cover')
        ->and($orgB->cover_path)->not->toBe($first);
    Storage::disk('public')->assertMissing((string) $first)->assertExists((string) $orgB->cover_path);

    ($this->inB1)()->get(route('brand.edit'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('brand.coverUrl', Storage::disk('public')->url((string) $orgB->cover_path)));

    ($this->inB1)()->delete(route('brand.cover.destroy'))->assertRedirect(route('brand.edit'));
    expect(($this->organization)($this->tenants->orgB)->cover_path)->toBeNull();
    Storage::disk('public')->assertMissing((string) $orgB->cover_path)->assertExists((string) $orgB->logo_path);
});

it('refuses an SVG, a tiny image or no file', function (string $field, string $route, ?UploadedFile $file): void {
    Storage::fake('public');

    ($this->inB1)()->post(route($route), [$field => $file])->assertSessionHasErrors($field);

    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'an SVG logo' => fn (): array => ['logo', 'brand.logo', UploadedFile::fake()->create('l.svg', 10, 'image/svg+xml')],
    'a tiny logo' => fn (): array => ['logo', 'brand.logo', UploadedFile::fake()->image('l.png', 64, 64)],
    'an SVG cover' => fn (): array => ['cover', 'brand.cover', UploadedFile::fake()->create('c.svg', 10, 'image/svg+xml')],
    'a narrow cover' => fn (): array => ['cover', 'brand.cover', UploadedFile::fake()->image('c.png', 400, 400)],
    'no cover' => fn (): array => ['cover', 'brand.cover', null],
]);

it('shows Brand in the menu to whoever acts for the organization only', function (): void {
    $franchisee = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->b1);
    $hqInA1 = $this->tenants->member($this->tenants->admin(User::factory()->create(), $this->tenants->orgA), $this->tenants->a1, BusinessRole::Owner);
    $brand = fn ($response): bool => $response->assertOk()->viewData('page')['props']['nav']['brand'];

    expect($brand(($this->inB1)()->get(route('dashboard'))))->toBeTrue()
        ->and($brand(($this->atHq)()->get(route('dashboard'))))->toBeTrue()
        ->and($brand(($this->as)($hqInA1, 'business:'.$this->tenants->a1->id)->get(route('dashboard'))))->toBeFalse()
        ->and($brand(($this->as)($franchisee, 'business:'.$this->tenants->a1->id)->get(route('dashboard'))))->toBeFalse()
        ->and($brand(($this->as)($staff, 'business:'.$this->tenants->b1->id)->get(route('counter.show'))))->toBeFalse();
});
