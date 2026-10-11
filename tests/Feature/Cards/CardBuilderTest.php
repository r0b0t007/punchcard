<?php

declare(strict_types=1);

use App\Actions\Rewards\DescribeCustomerReward;
use App\Actions\Rewards\ListCustomerRewards;
use App\Enums\BusinessRole;
use App\Enums\CardMode;
use App\Enums\RewardType;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Cards\CustomerCard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The card builder's saves and the cards page (CHW-32, B6/B7)
|--------------------------------------------------------------------------
|
| Whoever runs the program (an independent café's owner, a franchise's HQ)
| makes cards, changes them and publishes or pauses them. A new card starts
| paused, honoured by the organization's businesses. Once customers hold a
| card, its mode and tiers are fixed and its stamps only go down: the form
| says why. A franchisee's owner sees its cards, never changes them.
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
    $this->card = fn (int $id): LoyaltyCard => $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::query()->with('businesses')->findOrFail($id));
    $this->newest = fn (): LoyaltyCard => $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::query()->with('businesses')->latest('id')->firstOrFail());
    $this->hold = fn (LoyaltyCard $card): CardEnrollment => $this->tenants->enroll(User::factory()->create(), $card);
});

it('makes a paused card honoured by the organization\'s businesses, with the text colour for its brand colour', function (): void {
    ($this->inB1)()->post(route('cards.store'), ($this->fields)([
        'brand_color' => '#0f4c81',
        'stamp_style' => 'heart',
        'reward_value' => 5,
        'info' => ['hours' => ' 8h-20h ', 'phone' => '', 'links' => [['label' => 'Menu', 'url' => 'https://marshan.ma/menu']]],
        'terms' => 'One stamp per visit.',
    ]))->assertRedirect(route('cards.index'));

    $card = ($this->newest)();
    expect($card->organization_id)->toBe($this->tenants->orgB->id)
        ->and($card->active)->toBeFalse()
        ->and($card->businesses->modelKeys())->toBe([$this->tenants->b1->id])
        ->and($card->stamps_required)->toBe(8)
        ->and($card->reward_type)->toBe(RewardType::Item)
        ->and($card->reward_value)->toBeNull()
        ->and($card->colors)->toBe(['brand' => '#0F4C81', 'foreground' => '#FBF6EE'])
        ->and($card->stamp_style)->toBe('heart')
        ->and($card->info)->toBe(['hours' => '8h-20h', 'links' => [['label' => 'Menu', 'url' => 'https://marshan.ma/menu']]])
        ->and($card->daily_cap)->toBe(5);
});

it('gives a progressive card its top tier\'s stamps and reward', function (): void {
    ($this->inB1)()->post(route('cards.store'), ($this->fields)([
        'mode' => 'progressive',
        'stamps_required' => null,
        'reward_text' => null,
        'reward_type' => null,
        'tiers' => [['stamps' => 12, 'reward' => 'A cake'], ['stamps' => 5, 'reward' => 'A coffee']],
    ]))->assertSessionHasNoErrors();

    $card = ($this->newest)();
    expect($card->mode)->toBe(CardMode::Progressive)
        ->and($card->tiers)->toBe([['stamps' => 5, 'reward' => 'A coffee'], ['stamps' => 12, 'reward' => 'A cake']])
        ->and($card->stamps_required)->toBe(12)
        ->and($card->reward_text)->toBe('A cake');
});

it('changes everything on a card nobody holds yet', function (): void {
    ($this->inB1)()->put(route('cards.update', $this->tenants->cardB->id), ($this->fields)([
        'stamps_required' => 12,
        'reward_type' => 'percent',
        'reward_value' => 20,
        'reward_text' => '20 % off',
        'daily_cap' => null,
    ]))->assertRedirect(route('cards.index'));

    $card = ($this->card)($this->tenants->cardB->id);
    expect($card->stamps_required)->toBe(12)
        ->and($card->reward_type)->toBe(RewardType::Percent)
        ->and($card->reward_value)->toBe(20)
        ->and($card->daily_cap)->toBeNull();
});

it('lowers a held card\'s stamps, and says why it can\'t raise them or change its mode', function (): void {
    ($this->hold)($this->tenants->cardB);
    $required = ($this->card)($this->tenants->cardB->id)->stamps_required;

    ($this->inB1)()->put(route('cards.update', $this->tenants->cardB->id), ($this->fields)(['stamps_required' => $required + 1]))
        ->assertSessionHasErrors(['stamps_required' => 'Customers hold this card: its stamps can be lowered, never raised. Start a new card instead.']);
    ($this->inB1)()->put(route('cards.update', $this->tenants->cardB->id), ($this->fields)(['mode' => 'progressive', 'tiers' => [['stamps' => 5, 'reward' => 'Tea']]]))
        ->assertSessionHasErrors(['mode' => 'Customers hold this card: its mode and tiers are fixed.']);

    ($this->inB1)()->put(route('cards.update', $this->tenants->cardB->id), ($this->fields)(['stamps_required' => 5, 'name' => 'Renamed']))
        ->assertSessionHasNoErrors();
    expect(($this->card)($this->tenants->cardB->id))->stamps_required->toBe(5)->name->toBe('Renamed');
});

it('publishes and pauses a card', function (): void {
    ($this->inB1)()->patch(route('cards.status', $this->tenants->cardB->id), ['active' => false])->assertRedirect(route('cards.index'));
    expect(($this->card)($this->tenants->cardB->id)->active)->toBeFalse();

    ($this->inB1)()->patch(route('cards.status', $this->tenants->cardB->id), ['active' => true]);
    expect(($this->card)($this->tenants->cardB->id)->active)->toBeTrue();
});

it('refuses what the card can\'t show or the database would refuse', function (array $overrides, string $field): void {
    ($this->inB1)()->post(route('cards.store'), ($this->fields)($overrides))->assertSessionHasErrors($field);
})->with([
    'a script link' => [['info' => ['links' => [['label' => 'Menu', 'url' => 'javascript:alert(1)']]]], 'info.links.0.url'],
    'an ftp link' => [['info' => ['links' => [['label' => 'Menu', 'url' => 'ftp://marshan.ma/menu']]]], 'info.links.0.url'],
    'an unknown stamp style' => [['stamp_style' => 'skull'], 'stamp_style'],
    'a short colour' => [['brand_color' => '#abc'], 'brand_color'],
    'a colour name' => [['brand_color' => 'red'], 'brand_color'],
    'too few stamps' => [['stamps_required' => 4], 'stamps_required'],
    'more than 100 % off' => [['reward_type' => 'percent', 'reward_value' => 101], 'reward_value'],
    'an amount off without its value' => [['reward_type' => 'fixed'], 'reward_value'],
    'a progressive card without tiers' => [['mode' => 'progressive'], 'tiers'],
    'a top tier under 5 stamps' => [['mode' => 'progressive', 'tiers' => [['stamps' => 3, 'reward' => 'Tea']]], 'tiers'],
    'two tiers at the same count' => [['mode' => 'progressive', 'tiers' => [['stamps' => 6, 'reward' => 'Tea'], ['stamps' => 6, 'reward' => 'Cake']]], 'tiers.0.stamps'],
    'a negative cooldown' => [['cooldown_min' => -1], 'cooldown_min'],
    'a zero daily cap' => [['daily_cap' => 0], 'daily_cap'],
]);

it('lets a franchise\'s HQ change its card, never a franchisee\'s owner or staff', function (): void {
    $hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $franchisee = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $update = fn (User $as, string $tenant) => ($this->as)($as, $tenant)->put(route('cards.update', $this->tenants->cardA->id), ($this->fields)(['name' => 'Changed']));

    $update($franchisee, 'business:'.$this->tenants->a1->id)->assertForbidden();
    $update($staff, 'business:'.$this->tenants->a1->id)->assertForbidden();
    ($this->as)($franchisee, 'business:'.$this->tenants->a1->id)->post(route('cards.store'), ($this->fields)())->assertForbidden();
    ($this->as)($franchisee, 'business:'.$this->tenants->a1->id)->patch(route('cards.status', $this->tenants->cardA->id), ['active' => false])->assertForbidden();
    expect(($this->card)($this->tenants->cardA->id)->name)->not->toBe('Changed');

    $update($hq, 'org:'.$this->tenants->orgA->id)->assertRedirect(route('cards.index'));
    expect(($this->card)($this->tenants->cardA->id)->name)->toBe('Changed');

    ($this->as)($hq, 'org:'.$this->tenants->orgA->id)->post(route('cards.store'), ($this->fields)(['name' => 'Summer card']));
    expect(($this->newest)()->businesses->modelKeys())->toEqualCanonicalizing([$this->tenants->a1->id, $this->tenants->a2->id]);
});

it('never finds another organization\'s card', function (): void {
    ($this->inB1)()->put(route('cards.update', $this->tenants->cardA->id), ($this->fields)())->assertNotFound();
    ($this->inB1)()->patch(route('cards.status', $this->tenants->cardA->id), ['active' => false])->assertNotFound();
    ($this->inB1)()->post(route('cards.banner', $this->tenants->cardA->id), ['banner' => UploadedFile::fake()->image('b.png', 1200, 400)])->assertNotFound();
});

it('honours only the businesses still operating', function (): void {
    $this->context->bypass(fn () => $this->tenants->a2->forceFill(['archived_at' => now()])->save());
    $hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);

    ($this->as)($hq, 'org:'.$this->tenants->orgA->id)->post(route('cards.store'), ($this->fields)());

    expect(($this->newest)()->businesses->modelKeys())->toBe([$this->tenants->a1->id]);
});

it('stores a banner under a random name and removes the one it replaces; never an SVG', function (): void {
    Storage::fake('public');
    $upload = fn (UploadedFile $file) => ($this->inB1)()->post(route('cards.banner', $this->tenants->cardB->id), ['banner' => $file]);

    $upload(UploadedFile::fake()->image('my-banner.png', 1200, 400))->assertSessionHasNoErrors();
    $first = ($this->card)($this->tenants->cardB->id)->banner_path;
    $upload(UploadedFile::fake()->image('second.jpg', 1200, 400));
    $second = ($this->card)($this->tenants->cardB->id)->banner_path;

    expect($first)->toStartWith('banners/'.$this->tenants->orgB->id.'/')->not->toContain('my-banner')
        ->and($second)->not->toBe($first);
    Storage::disk('public')->assertMissing((string) $first)->assertExists((string) $second);

    $upload(UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml'))->assertSessionHasErrors('banner');
    $upload(UploadedFile::fake()->image('tiny.png', 300, 100))->assertSessionHasErrors('banner');
});

it('lists the organization\'s cards for whoever runs it, marking held ones and the card new customers get', function (): void {
    $draft = $this->context->bypass(function (): LoyaltyCard {
        $card = LoyaltyCard::factory()->for($this->tenants->orgB)->create(['name' => 'Draft', 'active' => false]);
        $card->businesses()->attach($this->tenants->b1->id);

        return $card;
    });
    $newer = $this->context->bypass(function (): LoyaltyCard {
        $card = LoyaltyCard::factory()->for($this->tenants->orgB)->create(['name' => 'Newer']);
        $card->businesses()->attach($this->tenants->b1->id);

        return $card;
    });
    ($this->hold)($this->tenants->cardB);

    ($this->inB1)()->get(route('cards.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('cards/index')
            ->where('canManage', true)
            ->has('cards', 3)
            ->where('cards.1.id', $newer->id)
            ->where('cards.1.forNewCustomers', false)
            ->where('cards.0.id', $this->tenants->cardB->id)
            ->where('cards.0.held', true)
            ->where('cards.0.forNewCustomers', true)
            ->where('cards.0.businesses', ['B1'])
            ->where('cards.2.id', $draft->id)
            ->where('cards.2.active', false)
            ->where('cards.2.held', false)
            ->where('cards.2.forNewCustomers', false));

    ($this->inB1)()->patch(route('cards.status', $this->tenants->cardB->id), ['active' => false]);
    ($this->inB1)()->patch(route('cards.status', $newer->id), ['active' => false]);
    // With no card running, none is marked: taps are refused.
    ($this->inB1)()->get(route('cards.index'))
        ->assertInertia(fn ($page) => $page->where('cards.0.forNewCustomers', false)
            ->where('cards.1.forNewCustomers', false)
            ->where('cards.2.forNewCustomers', false));
});

it('names the businesses where a card is the only one running, among those still operating', function (): void {
    $hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $inOrgA = fn () => ($this->as)($hq, 'org:'.$this->tenants->orgA->id);
    // A3 honoured the card, then closed.
    $this->context->bypass(function (): void {
        $a3 = Business::factory()->for($this->tenants->orgA)->create(['name' => 'A3']);
        $this->tenants->cardA->businesses()->attach($a3->id);
        $a3->forceFill(['archived_at' => now()])->save();
    });
    $atA2 = $this->context->bypass(function (): LoyaltyCard {
        $card = LoyaltyCard::factory()->for($this->tenants->orgA)->create(['name' => 'A2 only']);
        $card->businesses()->attach($this->tenants->a2->id);

        return $card;
    });

    $inOrgA()->get(route('cards.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('cards.0.id', $this->tenants->cardA->id)
            ->where('cards.0.businesses', ['A1', 'A2'])
            ->where('cards.0.onlyRunningAt', ['A1'])
            ->where('cards.1.id', $atA2->id)
            ->where('cards.1.onlyRunningAt', []));

    $inOrgA()->patch(route('cards.status', $atA2->id), ['active' => false]);
    $inOrgA()->get(route('cards.index'))
        ->assertInertia(fn ($page) => $page->where('cards.0.onlyRunningAt', ['A1', 'A2'])
            ->where('cards.1.onlyRunningAt', null));
});

it('shows each card in its own colours, else its organization\'s colour', function (): void {
    $this->context->bypass(fn () => $this->tenants->orgB->forceFill(['brand_color' => '#112233'])->save());
    $listed = fn () => ($this->inB1)()->get(route('cards.index'));

    $listed()->assertInertia(fn ($page) => $page->where('cards.0.brandColor', '#112233')->where('cards.0.brandForeground', null));

    $this->context->bypass(fn () => LoyaltyCard::query()->findOrFail($this->tenants->cardB->id)->forceFill(['colors' => ['brand' => '#0F4C81', 'foreground' => '#FBF6EE']])->save());
    $listed()->assertInertia(fn ($page) => $page->where('cards.0.brandColor', '#0F4C81')->where('cards.0.brandForeground', '#FBF6EE'));
});

it('shows a franchisee\'s owner its cards read only, nothing about who holds them; staff get nothing', function (): void {
    $franchisee = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    ($this->hold)($this->tenants->cardA);
    // A card of the franchise that only A2 honours.
    $this->context->bypass(fn () => LoyaltyCard::factory()->for($this->tenants->orgA)->create()->businesses()->attach($this->tenants->a2->id));

    ($this->as)($franchisee, 'business:'.$this->tenants->a1->id)->get(route('cards.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', false)
            ->has('cards', 1)
            ->where('cards.0.id', $this->tenants->cardA->id)
            ->where('cards.0.held', null)
            ->where('cards.0.businesses', null));
    ($this->as)($staff, 'business:'.$this->tenants->a1->id)->get(route('cards.index'))->assertForbidden();
});

it('reads the cards page in the same number of queries however many cards there are', function (): void {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->inB1)()->get(route('cards.index'))->assertOk();

        return count(DB::getQueryLog());
    };
    $count(); // Warms the permission cache.
    $before = $count();
    $this->context->bypass(function (): void {
        foreach (range(1, 3) as $i) {
            $card = LoyaltyCard::factory()->for($this->tenants->orgB)->create();
            $card->businesses()->attach($this->tenants->b1->id);
            $this->tenants->enroll(User::factory()->create(), $card);
        }
    });

    expect($count())->toBe($before);
});

it('shows customers the card\'s own brand colour, else the organization\'s', function (): void {
    $business = $this->context->bypass(fn (): Business => Business::query()->with('organization')->findOrFail($this->tenants->b1->id));
    $this->context->bypass(fn () => $business->organization->forceFill(['brand_color' => '#112233'])->save());
    $card = ($this->card)($this->tenants->cardB->id);

    expect(CustomerCard::of($business, $card)['brandColor'])->toBe('#112233');

    expect(CustomerCard::of($business, $card)['brandForeground'])->toBeNull();

    $card->colors = ['brand' => '#0F4C81', 'foreground' => '#FBF6EE'];
    expect(CustomerCard::of($business, $card))->brandColor->toBe('#0F4C81')->brandForeground->toBe('#FBF6EE');
});

it('offers the cards page in the sidebar to owners and org admins, never to staff', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->b1);

    ($this->inB1)()->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('nav.cards', true));
    ($this->as)($staff, 'business:'.$this->tenants->b1->id)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('nav.cards', false));
});

it('leaves out the other mode\'s fields the form still holds', function (): void {
    ($this->inB1)()->post(route('cards.store'), ($this->fields)(['tiers' => [['stamps' => 2, 'reward' => '']]]))->assertSessionHasNoErrors();
    ($this->inB1)()->post(route('cards.store'), ($this->fields)(['tiers' => []]))->assertSessionHasNoErrors();
    ($this->inB1)()->post(route('cards.store'), ($this->fields)([
        'mode' => 'progressive',
        'stamps_required' => 3,
        'reward_type' => 'percent',
        'reward_value' => null,
        'tiers' => [['stamps' => 6, 'reward' => 'A cake']],
    ]))->assertSessionHasNoErrors();

    expect(($this->newest)())->stamps_required->toBe(6)->reward_type->toBe(RewardType::Item);
});

it('keeps a held progressive card\'s tiers, however the form orders them', function (): void {
    $tiers = [['stamps' => 5, 'reward' => 'A coffee'], ['stamps' => 10, 'reward' => 'A cake']];
    $progressive = ($this->fields)(['mode' => 'progressive', 'tiers' => $tiers]);
    ($this->inB1)()->post(route('cards.store'), $progressive);
    $card = ($this->newest)();
    ($this->hold)($card);

    ($this->inB1)()->put(route('cards.update', $card->id), [...$progressive, 'name' => 'Reordered', 'tiers' => array_reverse($tiers)])->assertSessionHasNoErrors();
    expect(($this->card)($card->id)->name)->toBe('Reordered');

    ($this->inB1)()->put(route('cards.update', $card->id), [...$progressive, 'tiers' => [$tiers[0], ['stamps' => 10, 'reward' => 'Two cakes']]])
        ->assertSessionHasErrors(['mode' => 'Customers hold this card: its mode and tiers are fixed.']);
    ($this->inB1)()->put(route('cards.update', $card->id), [...$progressive, 'tiers' => [...$tiers, ['stamps' => 15, 'reward' => 'A lunch']]])
        ->assertSessionHasErrors('mode');
    expect(($this->card)($card->id)->tiers)->toBe($tiers);
});

it('refuses HQ working inside a franchisee\'s business, which only sees its cards', function (): void {
    // HQ who also runs A1 picks it in the tenant switcher.
    $hq = $this->tenants->member($this->tenants->admin(User::factory()->create(), $this->tenants->orgA), $this->tenants->a1, BusinessRole::Owner);
    $inA1 = fn () => ($this->as)($hq, 'business:'.$this->tenants->a1->id);

    $inA1()->post(route('cards.store'), ($this->fields)())->assertForbidden();
    $inA1()->put(route('cards.update', $this->tenants->cardA->id), ($this->fields)())->assertForbidden();
    $inA1()->patch(route('cards.status', $this->tenants->cardA->id), ['active' => false])->assertForbidden();
    $inA1()->post(route('cards.banner', $this->tenants->cardA->id), ['banner' => UploadedFile::fake()->image('b.png', 1200, 400)])->assertForbidden();
    $inA1()->get(route('cards.index'))->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false)->where('nav.cards', true));
});

it('shows the cards page to nobody outside a business or organization', function (): void {
    $this->actingAs(User::factory()->create())->get(route('cards.index'))->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route('cards.store'), ($this->fields)())->assertForbidden();
});

it('refuses more of what the builder can\'t keep', function (array $overrides, string $field): void {
    ($this->inB1)()->post(route('cards.store'), ($this->fields)($overrides))->assertSessionHasErrors($field);
})->with([
    'a blank tier reward' => [['mode' => 'progressive', 'tiers' => [['stamps' => 6, 'reward' => '   ']]], 'tiers.0.reward'],
    'six links' => [['info' => ['links' => array_fill(0, 6, ['label' => 'Menu', 'url' => 'https://marshan.ma'])]], 'info.links'],
    'a daily cap over 100' => [['daily_cap' => 101], 'daily_cap'],
    'a cooldown over a day' => [['cooldown_min' => 1441], 'cooldown_min'],
]);

it('refuses a status that is not a yes or no', function (): void {
    ($this->inB1)()->patch(route('cards.status', $this->tenants->cardB->id), ['active' => 'maybe'])->assertSessionHasErrors('active');
});

it('shows a reward in its card\'s colour, else its organization\'s', function (): void {
    $customer = User::factory()->create();
    $reward = $this->tenants->reward($this->tenants->enroll($customer, $this->tenants->cardB));
    $this->context->bypass(fn () => $this->tenants->orgB->forceFill(['brand_color' => '#112233'])->save());
    $colours = fn (): array => [
        app(DescribeCustomerReward::class)->handle($reward, $customer)['brandColor'],
        app(ListCustomerRewards::class)->handle($customer)[0]['brandColor'],
    ];

    expect($colours())->toBe(['#112233', '#112233']);

    $this->context->bypass(fn () => LoyaltyCard::query()->findOrFail($this->tenants->cardB->id)->forceFill(['colors' => ['brand' => '#0F4C81', 'foreground' => '#FBF6EE']])->save());
    expect($colours())->toBe(['#0F4C81', '#0F4C81']);
});

it('saves a held progressive card whose stored tiers are out of order, never setting them again', function (): void {
    $tiers = [['stamps' => 5, 'reward' => 'A coffee'], ['stamps' => 10, 'reward' => 'A cake']];
    $progressive = ($this->fields)(['mode' => 'progressive', 'tiers' => $tiers]);
    ($this->inB1)()->post(route('cards.store'), $progressive);
    $card = ($this->newest)();
    ($this->hold)($card);
    // As a manual edit could leave them.
    DB::table('loyalty_cards')->where('id', $card->id)->update(['tiers' => json_encode(array_reverse($tiers))]);

    ($this->inB1)()->put(route('cards.update', $card->id), [...$progressive, 'name' => 'Renamed'])->assertSessionHasNoErrors();

    expect(($this->card)($card->id))->name->toBe('Renamed')->tiers->toBe(array_reverse($tiers));
});
