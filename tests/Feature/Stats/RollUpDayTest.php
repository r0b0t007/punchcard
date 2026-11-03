<?php

declare(strict_types=1);

use App\Actions\Stats\RollUpDay;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\BusinessDailyStat;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A business's day rolled up from the ledger (CHW-45, B3)
|--------------------------------------------------------------------------
|
| One row for the whole business and one for each open site, each local
| day: who came in, who came in for the first time, stamps given, rewards
| unlocked and redeemed there. Days with nothing get a row too. In a
| franchise, A2's day never counts at A1.
|
*/

beforeEach(function (): void {
    // UTC+1 all year (Africa/Lagos): 10 Oct runs from 9 Oct 23:00 to 10 Oct 23:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 11:00:00', 'UTC'));
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->context->bypass(fn () => Location::query()->update(['timezone' => 'Africa/Lagos']));
    $this->site = $this->tenants->locationOf($this->tenants->a1);
    $this->customer = fn (): CardEnrollment => $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA);
    $this->stampAt = fn (CardEnrollment $enrollment, string $utc, array $overrides = [], ?Business $business = null): StampEvent => $this->tenants
        ->stamp($enrollment, $business ?? $this->tenants->a1, ['created_at' => $utc, ...$overrides]);
    $this->rollUp = fn (string $day = '2026-10-10', ?Business $business = null) => app(RollUpDay::class)->handle($business ?? $this->tenants->a1, $day);
    // Each of the business's rows that day, keyed by site ('business' for the whole of it).
    $this->rows = fn (string $day = '2026-10-10', ?Business $business = null): array => $this->context->bypass(fn (): array => BusinessDailyStat::query()
        ->where('business_id', ($business ?? $this->tenants->a1)->id)->where('day', $day)->get()
        ->mapWithKeys(fn (BusinessDailyStat $row): array => [$row->location_id ?? 'business' => $row->only(['visits', 'new_customers', 'stamps', 'rewards_unlocked', 'rewards_redeemed'])])
        ->all());
});

it('counts the day\'s visits, new customers, stamps and rewards, at the business and its site', function (): void {
    $new = ($this->customer)();
    $regular = ($this->customer)();
    ($this->stampAt)($regular, '2026-10-05 10:00:00');
    // 9 Oct 23:00 UTC is midnight, 10 Oct; 23:00 on 10 Oct is the 11th.
    $unlocking = ($this->stampAt)($new, '2026-10-09 23:00:00');
    ($this->stampAt)($new, '2026-10-10 22:59:59');
    ($this->stampAt)($new, '2026-10-10 23:00:00');
    ($this->stampAt)($regular, '2026-10-10 12:00:00');
    // Their second card's stamp: still one visit.
    $secondCard = $this->context->bypass(function (): LoyaltyCard {
        $card = LoyaltyCard::factory()->for($this->tenants->orgA)->create();
        $card->businesses()->attach($this->tenants->a1->id);

        return $card;
    });
    ($this->stampAt)($this->tenants->enroll($regular->user, $secondCard), '2026-10-10 12:01:00');
    ($this->stampAt)($regular, '2026-10-10 12:05:00', ['source' => StampSource::Bonus, 'qty' => 2, 'staff_id' => null, 'idempotency_key' => null]);
    ($this->stampAt)($regular, '2026-10-10 12:10:00', ['source' => StampSource::Correction, 'qty' => -1, 'reason' => 'Twice']);    // A birthday stamp is given, but nobody came in.
    ($this->stampAt)(($this->customer)(), '2026-10-10 08:00:00', ['source' => StampSource::Birthday, 'staff_id' => null, 'idempotency_key' => null]);
    $this->context->bypass(fn () => Reward::factory()->for($new, 'enrollment')->create(['stamp_event_id' => $unlocking->id]));
    $redeemed = fn (int $milestone, string $utc) => $this->context->bypass(fn () => Reward::factory()->for($regular, 'enrollment')->create(['milestone' => $milestone])->forceFill([
        'status' => RewardStatus::Redeemed, 'redeemed_at' => $utc,
        'redeemed_business_id' => $this->tenants->a1->id, 'redeemed_location_id' => $this->site->id,
    ])->save());
    $redeemed(1, '2026-10-10 18:00:00');
    $redeemed(2, '2026-10-10 23:30:00');

    ($this->rollUp)();

    $day = ['visits' => 2, 'new_customers' => 1, 'stamps' => 7, 'rewards_unlocked' => 1, 'rewards_redeemed' => 1];
    expect(($this->rows)())->toBe(['business' => $day, $this->site->id => $day]);
});

it('writes a day with nothing too, for every open site but no archived one', function (): void {
    $other = $this->context->bypass(fn (): Location => Location::factory()->create(['business_id' => $this->tenants->a1->id, 'timezone' => 'Africa/Lagos']));
    $this->context->bypass(fn () => Location::factory()->create(['business_id' => $this->tenants->a1->id, 'archived_at' => now()]));

    ($this->rollUp)();

    $nothing = ['visits' => 0, 'new_customers' => 0, 'stamps' => 0, 'rewards_unlocked' => 0, 'rewards_redeemed' => 0];
    expect(($this->rows)())->toBe(['business' => $nothing, $this->site->id => $nothing, $other->id => $nothing]);
});

it('counts a customer at two sites on one day once at the business and once at each, each site on its own day', function (): void {
    $kasbah = $this->context->bypass(fn (): Location => Location::factory()->create(['business_id' => $this->tenants->a1->id, 'timezone' => 'Africa/Lagos']));
    // UTC+4: 10 Oct 21:30 UTC is already the 11th there.
    $dubai = $this->context->bypass(fn (): Location => Location::factory()->create(['business_id' => $this->tenants->a1->id, 'timezone' => 'Asia/Dubai']));
    $customer = ($this->customer)();
    ($this->stampAt)($customer, '2026-10-10 09:00:00');
    ($this->stampAt)($customer, '2026-10-10 16:00:00', ['location_id' => $kasbah->id]);
    ($this->stampAt)($customer, '2026-10-10 21:30:00', ['location_id' => $dubai->id]);
    $this->context->bypass(fn () => $this->tenants->reward($customer)->forceFill([
        'status' => RewardStatus::Redeemed, 'redeemed_at' => '2026-10-10 16:05:00',
        'redeemed_business_id' => $this->tenants->a1->id, 'redeemed_location_id' => $kasbah->id,
    ])->save());

    ($this->rollUp)();
    ($this->rollUp)('2026-10-11');

    expect(array_map(fn (array $row): int => $row['visits'], ($this->rows)()))->toBe(['business' => 1, $this->site->id => 1, $kasbah->id => 1, $dubai->id => 0])
        ->and(array_map(fn (array $row): int => $row['rewards_redeemed'], ($this->rows)()))->toBe(['business' => 1, $this->site->id => 0, $kasbah->id => 1, $dubai->id => 0])
        // New at Kasbah, though not to the business.
        ->and(($this->rows)()[$kasbah->id]['new_customers'])->toBe(1)
        // The Dubai visit is on Dubai's day, for the business too, as its visit days count it.
        ->and(($this->rows)('2026-10-11')['business']['visits'])->toBe(1)
        ->and(($this->rows)('2026-10-11')['business']['new_customers'])->toBe(0)
        ->and(($this->rows)('2026-10-11')[$dubai->id]['visits'])->toBe(1)
        ->and(($this->rows)('2026-10-11')[$dubai->id]['new_customers'])->toBe(1);
});

it('keeps a visit to a site archived since in the business\'s day', function (): void {
    $closed = $this->context->bypass(fn (): Location => Location::factory()->create(['business_id' => $this->tenants->a1->id, 'timezone' => 'Africa/Lagos']));
    ($this->stampAt)(($this->customer)(), '2026-10-10 12:00:00', ['location_id' => $closed->id]);
    $this->context->bypass(fn () => $closed->forceFill(['archived_at' => now()])->save());

    ($this->rollUp)();

    expect(($this->rows)()['business']['visits'])->toBe(1)
        ->and(($this->rows)())->not->toHaveKey($closed->id);
});

it('never counts a sister franchise\'s day', function (): void {
    ($this->stampAt)(($this->customer)(), '2026-10-10 12:00:00', business: $this->tenants->a2);

    ($this->rollUp)();
    ($this->rollUp)(business: $this->tenants->a2);

    expect(($this->rows)()['business']['visits'])->toBe(0)
        ->and(($this->rows)(business: $this->tenants->a2)['business']['visits'])->toBe(1);
});

it('rewrites the day whole however often it runs', function (): void {
    $customer = ($this->customer)();
    ($this->stampAt)($customer, '2026-10-10 12:00:00');
    ($this->rollUp)();
    ($this->rollUp)();
    ($this->stampAt)(($this->customer)(), '2026-10-10 13:00:00');

    ($this->rollUp)();

    expect($this->context->bypass(fn (): int => BusinessDailyStat::query()->where('business_id', $this->tenants->a1->id)->count()))->toBe(2)
        ->and(($this->rows)()['business']['visits'])->toBe(2);
});
