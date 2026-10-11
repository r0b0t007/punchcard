<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Http\Middleware\SetTenant;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The card builder page (CHW-32, B6)
|--------------------------------------------------------------------------
|
| Whoever runs the program opens the builder for a new card or one of the
| organization's cards: the form's fields, whether customers hold the card
| (its mode and tiers are then fixed, its stamps only go down) and what the
| live preview needs. "Save draft" keeps a card paused, "Publish" runs it;
| saving a running card never pauses it. A colour only pure white or black
| text reads on saves with a warning.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->admin($this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner), $this->tenants->orgB);
    $this->as = fn (User $user, string $tenant) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => $tenant]);
    $this->inB1 = fn () => ($this->as)($this->owner, 'business:'.$this->tenants->b1->id);
    $this->fields = fn (array $overrides = []): array => [
        'name' => 'Café Marshan',
        'mode' => 'cyclic',
        'stamps_required' => 8,
        'reward_type' => 'item',
        'reward_text' => 'A free coffee',
        'cooldown_min' => 20,
        'daily_cap' => 5,
        ...$overrides,
    ];
    $this->card = fn (int $id): LoyaltyCard => $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::query()->findOrFail($id));
    $this->newest = fn (): LoyaltyCard => $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::query()->latest('id')->firstOrFail());
    $this->hold = fn (LoyaltyCard $card): CardEnrollment => $this->tenants->enroll(User::factory()->create(), $card);
});

it('opens a new card with the defaults, previewed with the business\'s name, logo and colour', function (): void {
    $this->context->bypass(fn () => $this->tenants->orgB->forceFill(['brand_color' => '#0F4C81', 'logo_path' => 'logos/1/logo.png'])->save());

    ($this->inB1)()->get(route('cards.create'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('cards/edit')
            ->where('card', null)
            ->where('fields.mode', 'cyclic')
            ->where('fields.stamps_required', 10)
            ->where('fields.reward_type', 'item')
            ->where('fields.brand_color', null)
            ->where('fields.stamp_style', 'dot')
            ->where('fields.tiers', [])
            ->where('fields.info', ['hours' => '', 'phone' => '', 'links' => []])
            ->where('fields.cooldown_min', 20)
            ->where('fields.daily_cap', 5)
            ->where('preview.businessName', 'B1')
            ->where('preview.organizationColor', '#0F4C81')
            ->where('preview.logoUrl', Storage::disk('public')->url('logos/1/logo.png')));
});

it('opens a card with its fields, its banner and whether customers hold it', function (): void {
    $card = $this->tenants->cardB;
    $this->context->bypass(fn () => $card->forceFill([
        'colors' => ['brand' => '#A63D2A', 'foreground' => '#FBF6EE'],
        'stamp_style' => 'star',
        'banner_path' => 'banners/1/banner.png',
        'info' => ['phone' => '+212 5 39 00 00 00'],
        'terms' => 'One stamp per visit.',
        'reward_type' => 'percent',
        'reward_value' => 10,
    ])->save());
    ($this->hold)($card);

    ($this->inB1)()->get(route('cards.edit', $card->id))->assertOk()
        ->assertInertia(fn ($page) => $page->component('cards/edit')
            ->where('card.id', $card->id)
            ->where('card.active', true)
            ->where('card.held', true)
            ->where('card.bannerUrl', Storage::disk('public')->url('banners/1/banner.png'))
            ->where('fields.name', $card->name)
            ->where('fields.stamps_required', $card->stamps_required)
            ->where('fields.reward_type', 'percent')
            ->where('fields.reward_value', 10)
            ->where('fields.brand_color', '#A63D2A')
            ->where('fields.stamp_style', 'star')
            ->where('fields.info', ['hours' => '', 'phone' => '+212 5 39 00 00 00', 'links' => []])
            ->where('fields.terms', 'One stamp per visit.'));
});

it('gives a progressive card\'s tiers to the form, lowest first', function (): void {
    $card = $this->tenants->cardB;
    $this->context->bypass(fn () => $card->forceFill(['mode' => 'progressive', 'tiers' => [['stamps' => 10, 'reward' => 'Cake'], ['stamps' => 5, 'reward' => 'Tea']], 'stamps_required' => 10])->save());

    ($this->inB1)()->get(route('cards.edit', $card->id))
        ->assertInertia(fn ($page) => $page->where('fields.mode', 'progressive')
            ->where('fields.tiers', [['stamps' => 5, 'reward' => 'Tea'], ['stamps' => 10, 'reward' => 'Cake']])
            ->where('card.held', false));
});

it('previews a franchise\'s card under the organization\'s name for HQ', function (): void {
    $hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);

    ($this->as)($hq, 'org:'.$this->tenants->orgA->id)->get(route('cards.create'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('preview.businessName', $this->tenants->orgA->name));
});

it('opens the builder only for whoever runs the program, never another organization\'s card', function (): void {
    $franchisee = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->b1);

    ($this->as)($franchisee, 'business:'.$this->tenants->a1->id)->get(route('cards.create'))->assertForbidden();
    ($this->as)($staff, 'business:'.$this->tenants->b1->id)->get(route('cards.create'))->assertForbidden();
    ($this->as)($franchisee, 'business:'.$this->tenants->a1->id)->get(route('cards.edit', $this->tenants->cardA->id))->assertForbidden();
    ($this->as)($staff, 'business:'.$this->tenants->b1->id)->get(route('cards.edit', $this->tenants->cardB->id))->assertForbidden();
    ($this->inB1)()->get(route('cards.edit', $this->tenants->cardA->id))->assertNotFound();

    // HQ who also runs A1, working inside it, only sees its cards there.
    $hq = $this->tenants->member($this->tenants->admin(User::factory()->create(), $this->tenants->orgA), $this->tenants->a1, BusinessRole::Owner);
    ($this->as)($hq, 'business:'.$this->tenants->a1->id)->get(route('cards.create'))->assertForbidden();
    ($this->as)($hq, 'business:'.$this->tenants->a1->id)->get(route('cards.edit', $this->tenants->cardA->id))->assertForbidden();
});

it('publishes nothing when a held card\'s change is refused', function (): void {
    $card = $this->tenants->cardB;
    $this->context->bypass(fn () => $card->forceFill(['active' => false, 'stamps_required' => 8])->save());
    ($this->hold)($card);

    ($this->inB1)()->put(route('cards.update', $card->id), ($this->fields)(['stamps_required' => 9, 'publish' => true]))
        ->assertSessionHasErrors('stamps_required');

    expect(($this->card)($card->id))->active->toBeFalse()->stamps_required->toBe(8);
});

it('publishes a new card when asked, else keeps it a draft', function (): void {
    ($this->inB1)()->post(route('cards.store'), ($this->fields)(['publish' => true]))
        ->assertRedirect(route('cards.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Card published.']);
    expect(($this->newest)()->active)->toBeTrue();

    ($this->inB1)()->post(route('cards.store'), ($this->fields)(['publish' => false]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Card saved.']);
    expect(($this->newest)()->active)->toBeFalse();
});

it('publishes a paused card when asked, and never pauses a running one on save', function (): void {
    $card = $this->tenants->cardB;
    $this->context->bypass(fn () => $card->forceFill(['active' => false])->save());

    ($this->inB1)()->put(route('cards.update', $card->id), ($this->fields)(['publish' => true]));
    expect(($this->card)($card->id)->active)->toBeTrue();

    ($this->inB1)()->put(route('cards.update', $card->id), ($this->fields)());
    expect(($this->card)($card->id)->active)->toBeTrue();
});

it('warns after saving a colour only pure white or black text reads on', function (): void {
    ($this->inB1)()->post(route('cards.store'), ($this->fields)(['brand_color' => '#808080']))
        ->assertInertiaFlash('toast.type', 'warning');
    ($this->inB1)()->post(route('cards.store'), ($this->fields)(['brand_color' => '#808080', 'publish' => true]))
        ->assertInertiaFlash('toast.message', 'Card published. Only pure white or black text reads on its colour: a darker or lighter shade will look better.');
    ($this->inB1)()->post(route('cards.store'), ($this->fields)(['brand_color' => '#3B2A20']))
        ->assertInertiaFlash('toast.type', 'success');
});

it('refuses a publish flag that is not a yes or no', function (): void {
    ($this->inB1)()->post(route('cards.store'), ($this->fields)(['publish' => 'soon']))->assertSessionHasErrors('publish');
});

it('shows the new banner on the builder after an upload', function (): void {
    Storage::fake('public');

    ($this->inB1)()->from(route('cards.edit', $this->tenants->cardB->id))
        ->post(route('cards.banner', $this->tenants->cardB->id), ['banner' => UploadedFile::fake()->image('b.png', 1200, 400)])
        ->assertRedirect(route('cards.edit', $this->tenants->cardB->id));

    ($this->inB1)()->get(route('cards.edit', $this->tenants->cardB->id))
        ->assertInertia(fn ($page) => $page->where('card.bannerUrl', Storage::disk('public')->url((string) ($this->card)($this->tenants->cardB->id)->banner_path)));
});
