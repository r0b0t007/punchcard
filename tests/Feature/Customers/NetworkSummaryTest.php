<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Actions\Customers\SummariseCustomer;
use App\Console\Commands\CustomersSummariseCommand;
use App\Enums\CustomerStatus;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\OrganizationCustomer;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A franchise's customers across its sites (CHW-365, B4 at network level)
|--------------------------------------------------------------------------
|
| Summarised with their row at each business: a day at A1 and A2 is one
| visit, and they go quiet and are lost only when they stop coming to every
| site. Their rewards redeemed at each site add up. No row once they are no
| business's customer, or their account is gone.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = User::factory()->create(['name' => 'Salma Tazi']);
    $this->enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);

    // A stamp at a site, then both businesses summarised, as the listener does for the one stamped.
    $this->stampAt = function (string $at, Business $business, StampSource $source = StampSource::Qr, int $qty = 1): StampEvent {
        $stamp = $this->tenants->stamp($this->enrollment, $business, [
            'source' => $source, 'qty' => $qty, 'created_at' => Carbon::parse($at),
            ...$source === StampSource::Correction ? ['reason' => 'Given twice'] : [],
        ]);
        app(SummariseCustomer::class)->handle($business->id, $this->tenants->orgA->id, $this->customer->id);

        return $stamp;
    };
    $this->network = fn (): ?OrganizationCustomer => $this->context->bypass(fn () => OrganizationCustomer::query()
        ->where('organization_id', $this->tenants->orgA->id)->where('user_id', $this->customer->id)->first());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('counts a day at two sites as one visit, and adds up the days at each', function (): void {
    ($this->stampAt)('2026-10-01 10:00:00', $this->tenants->a1);
    ($this->stampAt)('2026-10-01 17:00:00', $this->tenants->a2);
    ($this->stampAt)('2026-10-05 10:00:00', $this->tenants->a2);
    ($this->stampAt)('2026-10-09 10:00:00', $this->tenants->a1);

    expect(($this->network)())
        ->visits->toBe(3)
        ->first_visit_at->toDateTimeString()->toBe('2026-10-01 10:00:00')
        ->last_visit_at->toDateTimeString()->toBe('2026-10-09 10:00:00')
        ->usual_gap_days->toBe(4);
});

it('goes quiet and is lost only when they stop coming to every site', function (): void {
    // Going quiet at A1, but at A2 last week: active across the network.
    ($this->stampAt)('2026-10-11 10:00:00', $this->tenants->a1);
    ($this->stampAt)('2026-10-25 10:00:00', $this->tenants->a2);

    expect($this->context->bypass(fn () => BusinessCustomer::query()->where('business_id', $this->tenants->a1->id)->sole())->status(now()))->toBe(CustomerStatus::Quiet)
        ->and(($this->network)()->status(now()))->toBe(CustomerStatus::Active);

    $this->travel(40)->days();
    expect(($this->network)()->status(now()))->toBe(CustomerStatus::Quiet);

    $this->travel(30)->days();
    expect(($this->network)()->status(now()))->toBe(CustomerStatus::Lost);
});

it('keeps them while they are a customer of one site, and drops them after everything is taken back', function (): void {
    ($this->stampAt)('2026-10-01 10:00:00', $this->tenants->a1);
    ($this->stampAt)('2026-10-05 10:00:00', $this->tenants->a2);

    // Everything at A2 taken back: no longer A2's customer, so its days no longer count.
    ($this->stampAt)('2026-10-06 10:00:00', $this->tenants->a2, StampSource::Correction, -1);
    expect(($this->network)())->visits->toBe(1)->last_visit_at->toDateTimeString()->toBe('2026-10-01 10:00:00');

    ($this->stampAt)('2026-10-07 10:00:00', $this->tenants->a1, StampSource::Correction, -1);
    expect(($this->network)())->toBeNull();
});

it('counts the rewards redeemed at each site, one where they never stamped too', function (): void {
    // Every stamp at A1; one reward redeemed there, one at A2 (redeeming writes no stamp: never A2's customer).
    ($this->stampAt)('2026-10-01 10:00:00', $this->tenants->a1);

    foreach ([$this->tenants->a1, $this->tenants->a2] as $milestone => $business) {
        $this->context->bypass(fn () => Reward::factory()->for($this->enrollment, 'enrollment')->create(['milestone' => $milestone + 1])->forceFill([
            'status' => RewardStatus::Redeemed, 'redeemed_at' => '2026-10-03 10:00:00',
            'redeemed_business_id' => $business->id, 'redeemed_location_id' => $this->tenants->locationOf($business)->id,
        ])->save());
        app(SummariseCustomer::class)->handle($business->id, $this->tenants->orgA->id, $this->customer->id);
    }

    expect(($this->network)()->rewards_redeemed)->toBe(2)
        ->and($this->context->bypass(fn () => BusinessCustomer::query()->where('business_id', $this->tenants->a2->id)->count()))->toBe(0);
});

it('keeps no row for an anonymised account, nor a deleted one', function (string $how): void {
    ($this->stampAt)('2026-10-01 10:00:00', $this->tenants->a1);
    expect(($this->network)())->not->toBeNull();

    if ($how === 'deleted') {
        app(DeleteAccount::class)->handle($this->customer->refresh());
    } else {
        $this->context->bypass(fn () => User::query()->whereKey($this->customer->id)->update(['anonymised_at' => now()]));
        app(SummariseCustomer::class)->handle($this->tenants->a1->id, $this->tenants->orgA->id, $this->customer->id);
    }

    expect(($this->network)())->toBeNull();
})->with(['anonymised', 'deleted']);

it('fills the network rows from the ledger once, for customers summarised before', function (): void {
    $this->tenants->stamp($this->enrollment, $this->tenants->a1, ['created_at' => Carbon::parse('2026-10-01 10:00:00')]);
    $this->tenants->stamp($this->enrollment, $this->tenants->a2, ['created_at' => Carbon::parse('2026-10-02 10:00:00')]);
    Cache::forever('punchcard:customers:summarised:visit-days', true);

    $this->artisan('punchcard:customers:summarise', ['--once' => true])->assertSuccessful();

    expect(($this->network)()->visits)->toBe(2)
        ->and(Cache::has(CustomersSummariseCommand::DONE))->toBeTrue();
});
