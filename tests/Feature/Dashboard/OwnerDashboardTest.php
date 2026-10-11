<?php

declare(strict_types=1);

use App\Actions\Dashboard\DescribeDashboard;
use App\Enums\BusinessRole;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The owner dashboard (CHW-33, B3 lite)
|--------------------------------------------------------------------------
|
| Whoever runs a business sees the last 30 days there, against the 30 days
| before: active customers, visits (a customer's presence stamps on one
| local day), the return rate (active customers with visits on 2+ days) and
| rewards redeemed there; visits per day, the latest stamps and the top 10
| customers. Only this business's events count: a franchisee never sees
| its sibling's customers. Staff see none of it.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    // 12:00 local: the window is 12 Sept 00:00 to now. The sites keep UTC+1 all year (Africa/Lagos): Morocco's
    // own offsets move with each tz database release (2026.3 puts Casablanca at UTC+0 in October 2026).
    $this->travelTo(CarbonImmutable::parse('2026-10-11 11:00:00', 'UTC'));
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->context->bypass(fn () => Location::query()->update(['timezone' => 'Africa/Lagos']));
    $this->as = fn (User $user, string $tenant) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => $tenant]);
    $this->owner = $this->tenants->admin($this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner), $this->tenants->orgB);
    $this->customer = fn (LoyaltyCard $card): CardEnrollment => $this->tenants->enroll(User::factory()->create(), $card);
    // A stamp at a UTC time; Tenants::stamp() puts it at the business's location.
    $this->stampAt = fn (CardEnrollment $enrollment, Business $business, string $utc, array $overrides = []): StampEvent => $this->tenants->stamp($enrollment, $business, ['created_at' => $utc, ...$overrides]);
    $this->redeemedAt = fn (CardEnrollment $enrollment, Business $business, string $utc) => $this->context->bypass(fn () => $this->tenants->reward($enrollment)->forceFill([
        'status' => RewardStatus::Redeemed,
        'redeemed_at' => $utc,
        'redeemed_business_id' => $business->id,
        'redeemed_location_id' => $this->tenants->locationOf($business)->id,
    ])->save());
    // The dashboard as the business's owner (an org admin) sees it.
    $this->stats = function (Business $business): array {
        $this->context->set($this->context->bypass(fn () => $business->organization()->firstOrFail()), $business, orgAdmin: true);

        return app(DescribeDashboard::class)->handle($business, null, CarbonImmutable::now());
    };
});

it('counts a customer\'s presence stamps on one local day as one visit, whatever the card', function (): void {
    $first = ($this->customer)($this->tenants->cardB);
    $twoCards = $this->context->bypass(function (): LoyaltyCard {
        $card = LoyaltyCard::factory()->for($this->tenants->orgB)->create();
        $card->businesses()->attach($this->tenants->b1->id);

        return $card;
    });
    $second = $this->tenants->enroll($first->user, $twoCards);
    ($this->stampAt)($first, $this->tenants->b1, '2026-10-10 08:00:00');
    ($this->stampAt)($first, $this->tenants->b1, '2026-10-10 15:00:00');
    ($this->stampAt)($second, $this->tenants->b1, '2026-10-10 16:00:00', ['source' => StampSource::Manual, 'reason' => 'Forgot the phone']);
    // System stamps and corrections are not visits.
    ($this->stampAt)($first, $this->tenants->b1, '2026-10-09 09:00:00', ['source' => StampSource::Bonus, 'staff_id' => null, 'idempotency_key' => null]);
    ($this->stampAt)($first, $this->tenants->b1, '2026-10-08 09:00:00', ['source' => StampSource::Correction, 'qty' => -1, 'reason' => 'Twice']);

    $stats = ($this->stats)($this->tenants->b1);

    expect($stats['kpis']['visits']['value'])->toBe(1)
        ->and($stats['kpis']['activeCustomers']['value'])->toBe(1)
        ->and($stats['kpis']['returnRate']['value'])->toBe(0)
        ->and($stats['topCustomers'])->toHaveCount(1)
        ->and($stats['topCustomers'][0])->toMatchArray(['name' => $first->user->name, 'visits' => 1, 'lastVisit' => '2026-10-10']);
});

it('cuts the 30 days at local midnight in the business\'s timezone', function (): void {
    $inside = ($this->customer)($this->tenants->cardB);
    $before = ($this->customer)($this->tenants->cardB);
    // 12 Sept 00:30 local (UTC+1): the window's first day.
    ($this->stampAt)($inside, $this->tenants->b1, '2026-09-11 23:30:00');
    // 11 Sept 23:30 local: the previous window.
    ($this->stampAt)($before, $this->tenants->b1, '2026-09-11 22:30:00');

    $stats = ($this->stats)($this->tenants->b1);

    expect($stats['visitsPerDay'])->toHaveCount(30)
        ->and($stats['visitsPerDay'][0])->toBe(['date' => '2026-09-12', 'visits' => 1])
        ->and($stats['visitsPerDay'][29])->toBe(['date' => '2026-10-11', 'visits' => 0])
        ->and($stats['kpis']['activeCustomers'])->toBe(['value' => 1, 'change' => 0]);
});

it('compares each figure with the 30 days before, in percent, or points for the return rate', function (): void {
    [$a, $b, $c] = [($this->customer)($this->tenants->cardB), ($this->customer)($this->tenants->cardB), ($this->customer)($this->tenants->cardB)];
    // Previous window: a and b, b twice (return rate 50 %).
    ($this->stampAt)($a, $this->tenants->b1, '2026-09-01 10:00:00');
    ($this->stampAt)($b, $this->tenants->b1, '2026-09-02 10:00:00');
    ($this->stampAt)($b, $this->tenants->b1, '2026-09-03 10:00:00');
    // This window: a, b and c; a and b twice (return rate 67 %).
    foreach (['2026-10-01', '2026-10-02'] as $day) {
        ($this->stampAt)($a, $this->tenants->b1, "{$day} 10:00:00");
        ($this->stampAt)($b, $this->tenants->b1, "{$day} 11:00:00");
    }
    ($this->stampAt)($c, $this->tenants->b1, '2026-10-03 10:00:00');
    ($this->redeemedAt)($a, $this->tenants->b1, '2026-10-02 10:30:00');

    $kpis = ($this->stats)($this->tenants->b1)['kpis'];

    expect($kpis['activeCustomers'])->toBe(['value' => 3, 'change' => 50])
        ->and($kpis['visits'])->toBe(['value' => 5, 'change' => 67])
        ->and($kpis['returnRate'])->toBe(['value' => 67, 'change' => 17])
        // Nothing redeemed before: no percentage to give.
        ->and($kpis['rewardsRedeemed'])->toBe(['value' => 1, 'change' => null]);
});

it('counts only the rewards redeemed here in the window', function (): void {
    $here = ($this->customer)($this->tenants->cardA);
    ($this->redeemedAt)($here, $this->tenants->a1, '2026-10-05 10:00:00');
    ($this->redeemedAt)(($this->customer)($this->tenants->cardA), $this->tenants->a2, '2026-10-05 10:00:00');
    ($this->redeemedAt)(($this->customer)($this->tenants->cardA), $this->tenants->a1, '2026-08-01 10:00:00');
    // Unlocked but not redeemed.
    $this->tenants->reward(($this->customer)($this->tenants->cardA));

    expect(($this->stats)($this->tenants->a1)['kpis']['rewardsRedeemed'])->toBe(['value' => 1, 'change' => null]);
});

it('keeps a franchisee\'s figures, stamps and customers to its own business', function (): void {
    $both = ($this->customer)($this->tenants->cardA);
    $onlyA2 = ($this->customer)($this->tenants->cardA);
    ($this->stampAt)($both, $this->tenants->a1, '2026-10-05 10:00:00');
    ($this->stampAt)($both, $this->tenants->a2, '2026-10-06 10:00:00');
    ($this->stampAt)($onlyA2, $this->tenants->a2, '2026-10-07 10:00:00');
    ($this->redeemedAt)($onlyA2, $this->tenants->a2, '2026-10-07 10:30:00');
    $franchisee = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);

    ($this->as)($franchisee, 'business:'.$this->tenants->a1->id)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.kpis.visits.value', 1)
            ->where('stats.kpis.activeCustomers.value', 1)
            ->where('stats.kpis.rewardsRedeemed.value', 0)
            ->has('stats.recentStamps', 1)
            ->where('stats.recentStamps.0.customer', $both->user->name)
            ->has('stats.topCustomers', 1)
            ->where('stats.topCustomers.0.name', $both->user->name));

    // A system stamp here for a customer who only visited A2 shows without a name; a reward they redeemed here counts.
    ($this->stampAt)($onlyA2, $this->tenants->a1, '2026-10-08 10:00:00', ['source' => StampSource::Bonus, 'staff_id' => null, 'idempotency_key' => null]);
    $alsoOnlyA2 = ($this->customer)($this->tenants->cardA);
    ($this->stampAt)($alsoOnlyA2, $this->tenants->a2, '2026-10-08 09:00:00');
    ($this->redeemedAt)($alsoOnlyA2, $this->tenants->a1, '2026-10-08 10:30:00');
    ($this->as)($franchisee, 'business:'.$this->tenants->a1->id)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('stats.recentStamps.0.customer', null)
            ->where('stats.recentStamps.0.source', 'bonus')
            ->where('stats.kpis.rewardsRedeemed.value', 1)
            ->where('stats.kpis.activeCustomers.value', 1)
            ->has('stats.topCustomers', 1));

    // HQ, who also runs A1, sees only A1 there.
    $hq = $this->tenants->member($this->tenants->admin(User::factory()->create(), $this->tenants->orgA), $this->tenants->a1, BusinessRole::Owner);
    ($this->as)($hq, 'business:'.$this->tenants->a1->id)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('stats.kpis.visits.value', 1)->has('stats.recentStamps', 2)->has('stats.topCustomers', 1));

    // Another organization sees none of it.
    ($this->as)($this->owner, 'business:'.$this->tenants->b1->id)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('stats.kpis.visits.value', 0)->has('stats.recentStamps', 0)->has('stats.topCustomers', 0));
});

it('lists the latest stamps here, newest first, corrections included', function (): void {
    $customer = ($this->customer)($this->tenants->cardB);
    ($this->stampAt)($customer, $this->tenants->b1, '2026-10-09 10:00:00');
    ($this->stampAt)($customer, $this->tenants->b1, '2026-10-10 10:00:00', ['source' => StampSource::Correction, 'qty' => -1, 'reason' => 'Twice']);

    $recent = ($this->stats)($this->tenants->b1)['recentStamps'];

    expect($recent)->toHaveCount(2)
        ->and($recent[0])->toMatchArray(['customer' => $customer->user->name, 'source' => 'correction', 'sourceLabel' => 'Correction', 'qty' => -1, 'location' => 'B1 site'])
        ->and($recent[0]['at'])->toBe(['day' => 'other', 'date' => '2026-10-10', 'time' => '11:00'])
        ->and($recent[1]['qty'])->toBe(1);
});

it('ranks the top customers by visits, then by their latest visit', function (): void {
    $customers = array_map(fn (): CardEnrollment => ($this->customer)($this->tenants->cardB), range(1, 12));
    foreach ($customers as $index => $customer) {
        // Customers 0 to 11 make 1, 1, 2, 2, ... visits; the later of each pair visits last.
        foreach (range(0, intdiv($index, 2)) as $day) {
            ($this->stampAt)($customer, $this->tenants->b1, sprintf('2026-10-%02d %02d:00:00', $day + 1, 8 + $index));
        }
    }

    $top = ($this->stats)($this->tenants->b1)['topCustomers'];

    expect($top)->toHaveCount(10)
        ->and(array_column($top, 'name'))->toBe(array_map(fn (int $index): string => $customers[$index]->user->name, [11, 10, 9, 8, 7, 6, 5, 4, 3, 2]))
        ->and($top[0]['visits'])->toBe(6);
});

it('narrows every figure to one location, and ignores a location that is not the business\'s', function (): void {
    $terrace = $this->tenants->locationOf($this->tenants->b1);
    $kasbah = $this->context->bypass(fn (): Location => Location::factory()->create(['business_id' => $this->tenants->b1->id, 'name' => 'Kasbah']));
    $customer = ($this->customer)($this->tenants->cardB);
    ($this->stampAt)($customer, $this->tenants->b1, '2026-10-05 10:00:00');
    ($this->stampAt)($customer, $this->tenants->b1, '2026-10-06 10:00:00', ['location_id' => $kasbah->id]);
    $inB1 = fn () => ($this->as)($this->owner, 'business:'.$this->tenants->b1->id);

    $inB1()->get(route('dashboard', ['location' => $kasbah->id]))
        ->assertInertia(fn ($page) => $page->where('stats.location', $kasbah->id)
            ->where('stats.kpis.visits.value', 1)
            ->has('stats.recentStamps', 1)
            ->where('stats.recentStamps.0.location', 'Kasbah')
            ->where('stats.locations', [['id' => $terrace->id, 'name' => $terrace->name], ['id' => $kasbah->id, 'name' => 'Kasbah']]));

    // A location in another timezone counts its own days: 00:30 in Paris is already 11 October there.
    $this->context->bypass(fn () => $kasbah->forceFill(['timezone' => 'Europe/Paris'])->save());
    ($this->stampAt)($customer, $this->tenants->b1, '2026-10-10 22:30:00', ['location_id' => $kasbah->id]);
    $inB1()->get(route('dashboard', ['location' => $kasbah->id]))
        ->assertInertia(fn ($page) => $page->where('stats.visitsPerDay.29', ['date' => '2026-10-11', 'visits' => 1]));

    // An archived location of the business is no longer one to filter on.
    $this->context->bypass(fn () => $kasbah->forceFill(['archived_at' => now()])->save());
    $inB1()->get(route('dashboard', ['location' => $kasbah->id]))
        ->assertInertia(fn ($page) => $page->where('stats.location', null)->has('stats.locations', 1));

    $foreign = $this->tenants->locationOf($this->tenants->a1);
    $inB1()->get(route('dashboard', ['location' => $foreign->id]))
        ->assertInertia(fn ($page) => $page->where('stats.location', null)->where('stats.kpis.visits.value', 3));
});

it('counts only the business it is asked for, even read at the organization level', function (): void {
    $customer = ($this->customer)($this->tenants->cardA);
    ($this->stampAt)($customer, $this->tenants->a1, '2026-10-05 10:00:00');
    ($this->stampAt)($customer, $this->tenants->a2, '2026-10-06 10:00:00');
    $this->context->set($this->context->bypass(fn () => $this->tenants->orgA->fresh()), orgAdmin: true);

    $stats = app(DescribeDashboard::class)->handle($this->tenants->a1, null, CarbonImmutable::now());

    expect($stats['kpis']['visits']['value'])->toBe(1)
        ->and($stats['recentStamps'])->toHaveCount(1);
});

it('shows staff no figures, and HQ at the organization level a note instead', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->b1);
    $hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);

    ($this->as)($staff, 'business:'.$this->tenants->b1->id)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats', null)->where('atOrganization', false));
    ($this->as)($hq, 'org:'.$this->tenants->orgA->id)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats', null)->where('atOrganization', true));
});

it('reads the dashboard in the same number of queries however many customers stamped', function (): void {
    $count = function (int $customers): int {
        foreach (range(1, $customers) as $index) {
            $customer = ($this->customer)($this->tenants->cardB);
            ($this->stampAt)($customer, $this->tenants->b1, sprintf('2026-10-%02d 10:00:00', 1 + $index % 9));
            ($this->redeemedAt)($customer, $this->tenants->b1, '2026-10-10 10:00:00');
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->stats)($this->tenants->b1);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($count(3))->toBe($count(30));
});
