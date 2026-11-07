<?php

declare(strict_types=1);

use App\Actions\Customers\SummariseCustomer;
use App\Enums\BusinessRole;
use App\Enums\StampSource;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Refer a friend, on screen (CHW-47, designs 20a and 20c)
|--------------------------------------------------------------------------
|
| A customer's card (C6) offers their link and this month's stamps from
| friends while the card's referrals are on; the join page (C1) says a
| friend invited them, when the code would count; the customer's page (B5)
| counts the friends they invited who came in to this business only.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-07 12:00:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['referrals_enabled' => true, 'referral_monthly_cap' => 3])->save());
    $this->customer = User::factory()->create();
    $this->invite = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->context->bypass(fn () => $this->invite->forceFill(['referral_code' => 'KMPQ2XAB'])->save());
    $this->card = fn () => $this->actingAs($this->customer)->get(route('app.cards.show', $this->invite->id));
    $this->friend = fn (): CardEnrollment => $this->context->bypass(fn (): CardEnrollment => CardEnrollment::factory()->for($this->tenants->cardA, 'card')
        ->for(User::factory()->create())->create(['referred_by' => $this->invite->id]));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('offers their link to the business they last came to, and this month\'s stamps from friends', function (): void {
    $this->tenants->stamp($this->invite, $this->tenants->a1, ['created_at' => now()->subWeeks(2)]);
    $this->tenants->stamp($this->invite, $this->tenants->a2, ['created_at' => now()->subWeek()]);
    $this->tenants->stamp($this->invite, $this->tenants->a2, ['source' => StampSource::Referral, 'idempotency_key' => 'referral:1', 'staff_id' => null, 'created_at' => now()->subDay()]);
    $this->tenants->stamp($this->invite, $this->tenants->a2, ['source' => StampSource::Referral, 'idempotency_key' => 'referral:2', 'staff_id' => null, 'created_at' => now()->subMonth()]);

    ($this->card)()->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('app/card')
        ->where('referral', ['url' => route('join.show', $this->tenants->a2->slug).'?ref=KMPQ2XAB', 'cap' => 3, 'thisMonth' => 1]));
});

it('links a card never used yet to the first business honouring it', function (): void {
    ($this->card)()->assertInertia(fn (Assert $page): Assert => $page
        ->where('referral.url', route('join.show', $this->tenants->a1->slug).'?ref=KMPQ2XAB')->where('referral.thisMonth', 0));
});

it('never links to a business that gives someone new another card, or has no open site', function (): void {
    // A newer card at A1 and A2, where the older A card still runs: someone new would get the A card there.
    $newer = $this->context->bypass(function (): CardEnrollment {
        $card = LoyaltyCard::factory()->for($this->tenants->orgA)->create(['referrals_enabled' => true]);
        $card->businesses()->attach([$this->tenants->a1->id, $this->tenants->a2->id]);
        $enrollment = $this->tenants->enroll($this->customer, $card);
        $enrollment->forceFill(['referral_code' => 'NWRCARD2'])->save();

        return $enrollment;
    });
    $link = fn () => $this->actingAs($this->customer)->get(route('app.cards.show', $newer->id))->assertOk();

    $link()->assertInertia(fn (Assert $page): Assert => $page->where('referral', null));

    $this->context->bypass(fn () => $this->tenants->cardA->businesses()->detach($this->tenants->a2->id));
    $link()->assertInertia(fn (Assert $page): Assert => $page->where('referral.url', route('join.show', $this->tenants->a2->slug).'?ref=NWRCARD2'));

    $this->context->bypass(fn () => Location::query()->where('business_id', $this->tenants->a2->id)->update(['archived_at' => now()]));
    $link()->assertInertia(fn (Assert $page): Assert => $page->where('referral', null));
});

it('offers nothing while the card\'s referrals are off, or the card is paused', function (string $case): void {
    $this->context->bypass(fn () => $this->tenants->cardA->forceFill($case === 'off' ? ['referrals_enabled' => false] : ['active' => false])->save());

    ($this->card)()->assertInertia(fn (Assert $page): Assert => $page->where('referral', null));
})->with(['off', 'paused']);

it('tells someone who came by a friend\'s link, on that business\'s join page only', function (): void {
    $this->get(route('join.show', $this->tenants->a1->slug))->assertInertia(fn (Assert $page): Assert => $page->where('invited', false));
    $this->get(route('join.show', $this->tenants->a1->slug).'?ref=KMPQ2XAB')->assertInertia(fn (Assert $page): Assert => $page->where('invited', true));
    $this->get(route('join.show', $this->tenants->a2->slug))->assertInertia(fn (Assert $page): Assert => $page->where('invited', false));
});

it('never tells them so when the invitation would not count', function (string $case): void {
    $viewer = match ($case) {
        'their own link', 'a card they hold' => $this->customer,
        default => User::factory()->create(),
    };
    $code = $case === 'a made-up code' ? 'ABCDEFGH' : 'KMPQ2XAB';

    if ($case === 'referrals off') {
        $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['referrals_enabled' => false])->save());
    }

    if ($case === 'a card they hold') {
        $viewer = User::factory()->create();
        $this->tenants->enroll($viewer, $this->tenants->cardA);
    }

    $this->actingAs($viewer)->get(route('join.show', $this->tenants->a1->slug).'?ref='.$code)
        ->assertOk()->assertInertia(fn (Assert $page): Assert => $page->where('invited', false));
})->with(['a made-up code', 'referrals off', 'their own link', 'a card they hold']);

it('counts the friends a customer invited who came in to this business only', function (): void {
    $owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $this->tenants->stamp($this->invite, $this->tenants->a1);
    app(SummariseCustomer::class)->handle($this->tenants->a1->id, $this->tenants->orgA->id, $this->customer->id);
    $customer = $this->context->bypass(fn (): BusinessCustomer => BusinessCustomer::query()->where('business_id', $this->tenants->a1->id)->where('user_id', $this->customer->id)->sole());
    $cameHere = ($this->friend)();
    $this->tenants->stamp($cameHere, $this->tenants->a1);
    $cameToA2 = ($this->friend)();
    $this->tenants->stamp($cameToA2, $this->tenants->a2);
    ($this->friend)();

    $this->actingAs($owner)->get(route('customers.show', $customer->id))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('customer.friendsCameIn', 1)->missing('customer.friendsInvited'));
});
