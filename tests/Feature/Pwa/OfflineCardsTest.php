<?php

declare(strict_types=1);

use App\Actions\Cards\ListMyCards;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The customer's cards, kept for offline use (CHW-35, C5)
|--------------------------------------------------------------------------
|
| /api/me/cards answers My cards' data as JSON, which the service worker
| keeps (network first) for when the phone is offline. It is the signed-in
| customer's own cards only, never stored by a browser or a proxy, and a
| sign-out tells the browser to drop everything the site kept.
|
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 11:00:00', 'UTC'));
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->context->bypass(fn () => Location::query()->update(['timezone' => 'Africa/Lagos']));
    $this->customer = User::factory()->create(['name' => 'Salma Bennani']);
});

it('answers the customer\'s own cards as My cards shows them', function (): void {
    $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->tenants->enroll($this->customer, $this->tenants->cardB);
    $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA);

    $expected = app(ListMyCards::class)->handle($this->customer, CarbonImmutable::now());

    $this->actingAs($this->customer)->getJson(route('api.me.cards'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson([
            ...$expected,
            'userId' => $this->customer->id,
            'generatedAt' => '2026-10-11T11:00:00+00:00',
        ])
        ->assertJsonCount(2, 'cards');
});

it('answers a guest 401, never a sign-in page a cache could keep', function (): void {
    $this->get(route('api.me.cards'), ['Accept' => 'text/html'])
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/json');
});

it('reads a fixed number of queries, however many cards', function (): void {
    $count = function (int $cards): int {
        $customer = User::factory()->create();
        foreach (range(1, $cards) as $index) {
            $business = $index % 2 === 0 ? $this->tenants->a1 : $this->tenants->b1;
            $card = $this->context->bypass(function () use ($business): LoyaltyCard {
                $card = LoyaltyCard::factory()->for($business->organization()->firstOrFail())->create();
                $card->businesses()->attach($business->id);

                return $card;
            });
            $this->tenants->stamp($this->tenants->enroll($customer, $card), $business, ['created_at' => '2026-10-05 10:00:00']);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($customer)->getJson(route('api.me.cards'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // The first request also does one-time work (the session, the guard): measure after it.
    $count(1);

    expect($count(2))->toBe($count(6));
});

it('tells the browser to drop what the site kept on signing out', function (): void {
    $this->actingAs($this->customer)->post(route('logout'))
        ->assertRedirect()
        ->assertHeader('Clear-Site-Data', '"cache", "storage"');
});

it('does the same when the account is deleted', function (): void {
    $this->actingAs($this->customer)->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect()
        ->assertHeader('Clear-Site-Data', '"cache", "storage"');
});

it('leaves every other answer alone', function (): void {
    $this->actingAs($this->customer)->get(route('app.cards'))->assertOk()->assertHeaderMissing('Clear-Site-Data');
    $this->post(route('login.store'), ['email' => $this->customer->email, 'password' => 'wrong'])->assertHeaderMissing('Clear-Site-Data');
    // A deletion refused leaves the customer signed in, with their cards.
    $this->actingAs($this->customer)->delete(route('profile.destroy'), ['password' => 'wrong'])->assertHeaderMissing('Clear-Site-Data');
});
