<?php

declare(strict_types=1);

use App\Http\Middleware\SetTenant;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Models\User;
use App\Support\Branding\CardBrand;
use App\Support\Branding\Contrast;
use App\Support\Cards\CustomerCard;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The brand on customer screens (CHW-135, designs 24b and 24c)
|--------------------------------------------------------------------------
|
| A card paints with its own colour, else its organization's brand; a
| franchise's cards always with the brand. The text colour on either is
| worked out by the server. The cover sits on the tap screens and on the card
| page when the card has no banner of its own.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->set = fn (Organization|LoyaltyCard $model, array $values) => $this->context->bypass(fn () => $model->forceFill($values)->save());
    $this->fresh = fn (Organization $organization): Organization => $this->context->bypass(fn (): Organization => Organization::query()->findOrFail($organization->id));
    $this->hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $this->atHq = fn () => $this->actingAs($this->hq)->withSession([SetTenant::SESSION_KEY => 'org:'.$this->tenants->orgA->id]);
});

it('paints an independent café\'s card with its own colour, else the brand, with text that reads on it', function (): void {
    ($this->set)($this->tenants->orgB, ['brand_color' => '#0F5E63']);
    $orgB = ($this->fresh)($this->tenants->orgB);

    expect(CardBrand::of($this->tenants->cardB, $orgB))->toBe(['brandColor' => '#0F5E63', 'brandForeground' => Contrast::readableForeground('#0F5E63')])
        ->and(Contrast::ratio('#0F5E63', (string) $orgB->brandForeground()))->toBeGreaterThanOrEqual(4.5);

    ($this->set)($this->tenants->cardB, ['colors' => ['brand' => '#A63D2A', 'foreground' => '#FBF6EE']]);

    expect(CardBrand::of($this->tenants->cardB, $orgB))->toBe(['brandColor' => '#A63D2A', 'brandForeground' => '#FBF6EE'])
        ->and(CardBrand::of(null, null))->toBe(['brandColor' => null, 'brandForeground' => null]);
});

it('paints a franchise\'s cards with its brand only, whatever colour a card kept', function (): void {
    ($this->set)($this->tenants->orgA, ['brand_color' => '#0F4C81']);
    ($this->set)($this->tenants->cardA, ['colors' => ['brand' => '#A63D2A', 'foreground' => '#FBF6EE']]);
    $orgA = ($this->fresh)($this->tenants->orgA);
    $a1 = $this->context->bypass(fn () => $this->tenants->a1->load('organization'));

    expect(CardBrand::of($this->tenants->cardA, $orgA)['brandColor'])->toBe('#0F4C81')
        ->and(CustomerCard::of($a1, $this->tenants->cardA)['brandColor'])->toBe('#0F4C81')
        ->and(CustomerCard::ofProgram($orgA, $this->tenants->cardA)['brandForeground'])->toBe($orgA->brandForeground());
});

it('shows the card\'s banner on customer screens, else the organization\'s cover', function (): void {
    ($this->set)($this->tenants->orgB, ['cover_path' => 'covers/1/cover.jpg']);
    $b1 = $this->context->bypass(fn () => $this->tenants->b1->load('organization'));

    expect(CustomerCard::of($b1, $this->tenants->cardB)['coverUrl'])->toEndWith('covers/1/cover.jpg');

    ($this->set)($this->tenants->cardB, ['banner_path' => 'banners/1/banner.jpg']);

    expect(CustomerCard::of($b1, $this->tenants->cardB->refresh())['coverUrl'])->toEndWith('banners/1/banner.jpg');
});

it('shows a customer the organization\'s cover on the card page when the card has no banner', function (): void {
    ($this->set)($this->tenants->orgB, ['cover_path' => 'covers/1/cover.jpg']);
    $customer = User::factory()->create();
    $enrollment = $this->tenants->enroll($customer, $this->tenants->cardB);

    $this->actingAs($customer)->get(route('app.cards.show', $enrollment->id))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('bannerUrl', fn (string $url): bool => str_ends_with($url, 'covers/1/cover.jpg')));
});

it('gives a franchise\'s cards no colour of their own in the builder', function (): void {
    ($this->set)($this->tenants->cardA, ['colors' => ['brand' => '#A63D2A', 'foreground' => '#FBF6EE']]);
    $fields = [
        'name' => 'Atlas card', 'mode' => 'cyclic', 'stamps_required' => 8, 'reward_type' => 'item',
        'reward_text' => 'A free coffee', 'cooldown_min' => 20, 'daily_cap' => 5,
    ];

    ($this->atHq)()->get(route('cards.edit', $this->tenants->cardA->id))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('preview.franchise', true)->where('fields.brand_color', null));

    ($this->atHq)()->put(route('cards.update', $this->tenants->cardA->id), [...$fields, 'brand_color' => '#123456'])->assertSessionHasErrors('brand_color');
    ($this->atHq)()->put(route('cards.update', $this->tenants->cardA->id), [...$fields, 'brand_color' => null])->assertSessionHasNoErrors();

    expect($this->context->bypass(fn () => LoyaltyCard::query()->findOrFail($this->tenants->cardA->id)->colors))->toBeNull();
});
