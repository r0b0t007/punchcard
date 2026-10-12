<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\PlatformRole;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\Reward;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| My cards (CHW-27, C5)
|--------------------------------------------------------------------------
|
| A customer's home: every card they hold, the most recently used first,
| each in its program's look, with its rewards ready and how long ago they
| last visited. Customers land here after signing in; anyone who runs or
| works in a business keeps their dashboard.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    // 12:00 local; the sites keep UTC+1 all year (Africa/Lagos), whatever Morocco's tz rules do.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 11:00:00', 'UTC'));
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->context->bypass(fn () => Location::query()->update(['timezone' => 'Africa/Lagos']));
    $this->customer = User::factory()->create(['name' => 'Salma Bennani']);
    $this->usedAt = fn (CardEnrollment $enrollment, string $utc) => $this->context->bypass(fn () => $enrollment->forceFill(['last_stamp_at' => $utc])->save());
});

it('lists the customer\'s own cards, the most recently used first, each in its program\'s look', function (): void {
    $this->context->bypass(fn () => $this->tenants->orgA->forceFill(['brand_color' => '#0F5E63'])->save());
    $atA = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $atB = $this->tenants->enroll($this->customer, $this->tenants->cardB);
    ($this->usedAt)($atA, '2026-10-09 10:00:00');
    ($this->usedAt)($atB, '2026-10-10 10:00:00');
    $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA);

    $this->actingAs($this->customer)->get(route('app.cards'))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn ($page) => $page->component('app/cards')
            ->where('firstName', 'Salma')
            ->has('cards', 2)
            ->where('cards.0.id', $atB->id)
            ->where('cards.0.card.businessName', $this->tenants->orgB->name)
            ->where('cards.0.card.cardName', 'B card')
            ->where('cards.1.id', $atA->id)
            ->where('cards.1.card.brandColor', '#0F5E63'));
});

it('counts the rewards ready on each card and in all', function (): void {
    $atA = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $atB = $this->tenants->enroll($this->customer, $this->tenants->cardB);
    $this->tenants->reward($atA);
    $this->tenants->reward($atB);
    $this->context->bypass(function () use ($atA): void {
        Reward::factory()->for($atA, 'enrollment')->create(['milestone' => 2]);
        Reward::factory()->for($atA, 'enrollment')->create(['milestone' => 3, 'status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a1->id]);
    });

    $this->actingAs($this->customer)->get(route('app.cards'))
        ->assertInertia(fn ($page) => $page->where('rewardsReady', 3)
            ->where('cards.0.rewardsReady', 1)
            ->where('cards.1.rewardsReady', 2));
});

it('shows a progressive card\'s lifetime stamps and marks a paused card', function (): void {
    $this->context->bypass(fn () => $this->tenants->cardB->forceFill([
        'mode' => 'progressive', 'tiers' => [['stamps' => 5, 'reward' => 'Tea'], ['stamps' => 10, 'reward' => 'Cake']], 'stamps_required' => 10, 'active' => false,
    ])->save());
    $enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardB);
    $this->context->bypass(fn () => $enrollment->forceFill(['current_stamps' => 0, 'lifetime_stamps' => 7])->save());

    $this->actingAs($this->customer)->get(route('app.cards'))
        ->assertInertia(fn ($page) => $page->where('cards.0.card.stampsCollected', 7)
            ->where('cards.0.card.stampsRequired', 10)
            ->where('cards.0.paused', true));
});

it('says how many local days ago the customer last stamped in person', function (): void {
    $enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    // 10 Oct 00:30 local (UTC+1), still 9 Oct in UTC: one local day before 11 Oct.
    $this->tenants->stamp($enrollment, $this->tenants->a1, ['created_at' => '2026-10-09 23:30:00']);
    // A bonus this morning is not a visit.
    $this->tenants->stamp($enrollment, $this->tenants->a1, ['created_at' => '2026-10-11 08:00:00', 'source' => StampSource::Bonus, 'staff_id' => null, 'idempotency_key' => null]);
    $fresh = $this->tenants->enroll($this->customer, $this->tenants->cardB);

    $this->actingAs($this->customer)->get(route('app.cards'))
        ->assertInertia(fn ($page) => $page->where('cards.0.id', $fresh->id)
            ->where('cards.0.lastVisitDaysAgo', null)
            ->where('cards.1.lastVisitDaysAgo', 1));
});

it('reads the page in the same number of queries however many cards', function (): void {
    $count = function (int $cards): int {
        $customer = User::factory()->create();
        foreach (range(1, $cards) as $index) {
            // A card of each organization in turn, each new, honoured where the stamp is given.
            $business = $index % 2 === 0 ? $this->tenants->a1 : $this->tenants->b1;
            $card = $this->context->bypass(function () use ($business): LoyaltyCard {
                $card = LoyaltyCard::factory()->for($business->organization()->firstOrFail())->create();
                $card->businesses()->attach($business->id);

                return $card;
            });
            $enrollment = $this->tenants->enroll($customer, $card);
            $this->tenants->stamp($enrollment, $business, ['created_at' => '2026-10-05 10:00:00']);
            $this->tenants->reward($enrollment);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($customer)->get(route('app.cards'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // The first request also does one-time work (the session, the guard): measure after it.
    $count(1);

    expect($count(2))->toBe($count(6));
});

it('sends guests to sign in', function (): void {
    $this->get(route('app.cards'))->assertRedirect(route('login'));
});

it('sends whoever runs or works in a business home to their dashboard, and still shows them their own cards', function (User $user): void {
    $this->actingAs($user)->get(route('start'))->assertRedirect(route('dashboard'));

    $this->tenants->enroll($user, $this->tenants->cardB);
    $this->actingAs($user)->get(route('app.cards'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('cards', 1));
})->with([
    'an owner' => fn () => $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner),
    'staff' => fn () => $this->tenants->member(User::factory()->create(), $this->tenants->a1),
    'an org admin' => fn () => $this->tenants->admin(User::factory()->create(), $this->tenants->orgA),
]);

it('lands a customer on their cards after signing in, and sends one who opens the dashboard there', function (): void {
    $this->post(route('login.store'), ['email' => $this->customer->email, 'password' => 'password'])
        ->assertRedirect(route('start', absolute: false));

    $this->actingAs($this->customer)->get(route('start'))->assertRedirect(route('app.cards'));
    $this->actingAs($this->customer)->get(route('start', ['verified' => 1]))->assertRedirect(route('app.cards', ['verified' => 1]));
    $this->actingAs($this->customer)->get(route('dashboard'))->assertRedirect(route('app.cards'));
});

it('treats a business that was given up as no business: its owner goes to their cards', function (): void {
    // A cancelled setup keeps its rows, archived (CancelSetup); ResolveTenant gives no tenant there.
    $owner = $this->tenants->admin($this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner), $this->tenants->orgB);
    $this->context->bypass(function (): void {
        $this->tenants->b1->forceFill(['archived_at' => now()])->save();
        $this->tenants->orgB->forceFill(['archived_at' => now()])->save();
    });

    $this->actingAs($owner)->get(route('start'))->assertRedirect(route('app.cards'));
    $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('app.cards'));
});

it('sends the platform admin home to the admin panel', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));

    $this->actingAs($admin)->get(route('start'))->assertRedirect(url('/admin'));
});

it('gives a customer\'s settings the customer layout, never a business user\'s', function (): void {
    $owner = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner);

    $this->actingAs($this->customer)->get(route('profile.edit'))->assertInertia(fn ($page) => $page->where('customer', true));
    $this->actingAs($owner)->get(route('profile.edit'))->assertInertia(fn ($page) => $page->where('customer', false));
    $this->actingAs($this->customer)->get(route('app.cards'))->assertInertia(fn ($page) => $page->where('customer', false));
});
