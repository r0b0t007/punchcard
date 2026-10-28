<?php

declare(strict_types=1);

use App\Actions\Stamps\RecentStamps;
use App\Enums\BusinessRole;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Counter mode's live feed (CHW-29, S1)
|--------------------------------------------------------------------------
|
| The last stamps given where the staff member works, newest first, as they
| land (the page polls): the customer's first name and card, how many, how
| and when. Staff limited to one location see that location's; nobody ever
| sees another business's.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-16 09:00:00');
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->as = fn (User $user, ?Business $business = null) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'business:'.($business ?? $this->tenants->a1)->id]);
    $this->stampFor = function (string $name, Business $business, array $overrides = []) {
        // Each organization's own card: B's business honours only B's.
        $card = $business->organization_id === $this->tenants->orgB->id ? $this->tenants->cardB : $this->tenants->cardA;
        $enrollment = $this->tenants->enroll(User::factory()->create(['name' => $name]), $card);

        return $this->tenants->stamp($enrollment, $business, $overrides);
    };
    $this->feed = fn (User $user) => ($this->as)($user)->get(route('counter.show'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows staff the last ten stamps here, newest first, by first name and card', function (): void {
    foreach (range(1, 11) as $minute) {
        ($this->stampFor)("Customer{$minute} Family", $this->tenants->a1, ['created_at' => now()->subMinutes(12 - $minute)]);
    }

    ($this->feed)($this->staff)->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->has('feed', 10)
        ->where('feed.0.customer', 'Customer11')
        ->where('feed.0.card', 'A card')
        ->where('feed.0.qty', 1)
        ->where('feed.0.source', 'qr')
        ->where('feed.9.customer', 'Customer2'));
});

it('says how many seconds ago each stamp landed, for "Just now" (CHW-220)', function (): void {
    ($this->stampFor)('Earlier Guest', $this->tenants->a1, ['created_at' => now()->subMinutes(5)]);
    ($this->stampFor)('Fresh Guest', $this->tenants->a1, ['created_at' => now()->subSeconds(12)]);

    ($this->feed)($this->staff)->assertInertia(fn (Assert $page): Assert => $page
        ->where('feed.0.customer', 'Fresh')
        ->where('feed.0.secondsAgo', 12)
        ->where('feed.1.secondsAgo', 300));
});

it('shows staff limited to one location only that location\'s stamps', function (): void {
    $terrace = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create(['name' => 'A1 terrace']));
    $this->context->bypass(fn () => BusinessMember::query()->where('user_id', $this->staff->id)->update(['location_id' => $terrace->id]));
    ($this->stampFor)('Main Room', $this->tenants->a1);
    ($this->stampFor)('Terrace Guest', $this->tenants->a1, ['location_id' => $terrace->id]);

    ($this->feed)($this->staff)->assertInertia(fn (Assert $page): Assert => $page
        ->has('feed', 1)
        ->where('feed.0.customer', 'Terrace'));
});

it('never shows another business\'s stamps (both boundaries)', function (string $other): void {
    ($this->stampFor)('Ours Here', $this->tenants->a1);
    ($this->stampFor)('Theirs There', $this->tenants->{$other});

    ($this->feed)($this->staff)->assertInertia(fn (Assert $page): Assert => $page
        ->has('feed', 1)
        ->where('feed.0.customer', 'Ours'));
})->with(['franchisee A2' => ['a2'], 'organization B' => ['b1']]);

it('refreshes the feed with the stampers on a poll', function (): void {
    ($this->stampFor)('Salma Tazi', $this->tenants->a1);

    ($this->as)($this->staff)->get(route('counter.show'))->assertInertia(fn (Assert $page): Assert => $page
        ->reloadOnly(['stampers', 'feed'], fn (Assert $reload): Assert => $reload->where('feed.0.customer', 'Salma')));
});

it('keeps full names on the owner\'s dashboard feed', function (): void {
    $owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    ($this->stampFor)('Salma Tazi', $this->tenants->a1);

    ($this->as)($owner)->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('stats.recentStamps.0.customer', 'Salma Tazi')
        ->where('stats.recentStamps.0.card', 'A card'));
});

it('takes the first word of any name, and no name from a blank one', function (): void {
    ($this->stampFor)("Salma\u{00A0}Tazi", $this->tenants->a1, ['created_at' => now()->subMinute()]);
    ($this->stampFor)('   ', $this->tenants->a1);

    ($this->feed)($this->staff)->assertInertia(fn (Assert $page): Assert => $page
        ->where('feed.0.customer', null)
        ->where('feed.1.customer', 'Salma'));
});

it('keeps staff to their location whatever the feed is asked for', function (): void {
    $terrace = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create(['name' => 'A1 terrace']));
    ($this->stampFor)('Main Room', $this->tenants->a1);
    ($this->stampFor)('Terrace Guest', $this->tenants->a1, ['location_id' => $terrace->id]);
    $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Staff, locationId: $terrace->id);

    $feed = app(RecentStamps::class)->handle($this->tenants->a1, null, CarbonImmutable::now(), firstNames: true);

    expect(array_column($feed, 'customer'))->toBe(['Terrace']);
});
