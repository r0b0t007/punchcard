<?php

declare(strict_types=1);

use App\Actions\Customers\DescribeCustomer;
use App\Actions\Customers\SummariseCustomer;
use App\Enums\BusinessRole;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| One customer (CHW-44, B5, design 15c)
|--------------------------------------------------------------------------
|
| Whoever runs the business sees a customer's status, what they usually do,
| their progress on each card (shared across a franchise) and what happened
| at this business, newest first, more on asking. A sister franchisee's
| stamps count on the card, never in this history.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $this->staff = $this->tenants->member(User::factory()->create(['name' => 'Yasmine Alaoui']), $this->tenants->a1);
    $this->user = User::factory()->create(['name' => 'Salma Tazi']);
    $this->enrollment = $this->tenants->enroll($this->user, $this->tenants->cardA);
    // A fixed offset (UTC+4).
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save());

    // A tap is a stamper's, with its tag and a counter that only goes up.
    $taps = new stdClass;
    $taps->stampers = [];
    $taps->counter = 0;
    $this->stampAt = function (string $at, StampSource $source = StampSource::Nfc, int $qty = 1, ?Business $business = null, array $values = []) use ($taps) {
        $business ??= $this->tenants->a1;

        if ($source === StampSource::Nfc) {
            $stamper = $taps->stampers[$business->id] ??= $this->tenants->stamper($business);
            $values += ['stamper_id' => $stamper->id, 'nfc_tag_id' => $stamper->nfc_tag_id, 'counter' => ++$taps->counter];
        }

        return $this->tenants->stamp($this->enrollment, $business, ['source' => $source, 'qty' => $qty, 'created_at' => Carbon::parse($at), ...$values]);
    };
    $this->summarised = function (?Business $business = null): BusinessCustomer {
        $business ??= $this->tenants->a1;
        app(SummariseCustomer::class)->handle($business->id, $business->organization_id, $this->user->id);

        return $this->context->bypass(fn (): BusinessCustomer => BusinessCustomer::query()->where('business_id', $business->id)->where('user_id', $this->user->id)->sole());
    };
    $this->open = fn (BusinessCustomer $customer, array $query = [], ?User $user = null) => $this->actingAs($user ?? $this->owner)
        ->withSession([SetTenant::SESSION_KEY => 'business:'.$this->tenants->a1->id])
        ->get(route('customers.show', [$customer->id, ...$query]));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows the owner a customer: status, habits, card progress and what happened here, newest first', function (): void {
    $tap = ($this->stampAt)('2026-10-20 06:00:00');
    $scan = ($this->stampAt)('2026-10-25 06:00:00', StampSource::Qr, values: ['staff_id' => $this->staff->id]);
    $byHand = ($this->stampAt)('2026-10-28 06:00:00', StampSource::Manual, values: ['staff_id' => $this->staff->id, 'reason' => 'Card forgotten']);
    $takenBack = ($this->stampAt)('2026-10-28 06:01:00', StampSource::Correction, -1, values: ['staff_id' => $this->staff->id, 'reason' => 'Given twice']);
    // At the sister franchisee: on the card, not in A1's history.
    ($this->stampAt)('2026-10-29 06:00:00', business: $this->tenants->a2);
    $redeem = fn (Business $at, int $milestone): Reward => $this->context->bypass(fn (): Reward => tap(Reward::factory()->for($this->enrollment, 'enrollment')->create(['milestone' => $milestone]), fn (Reward $reward) => $reward->forceFill([
        'status' => RewardStatus::Redeemed, 'redeemed_at' => '2026-10-30 14:00:00', 'redeemed_by' => $this->staff->id,
        'redeemed_business_id' => $at->id, 'redeemed_location_id' => $this->tenants->locationOf($at)->id,
    ])->save()));
    $reward = $redeem($this->tenants->a1, 1);
    // Redeemed at the sister franchisee: not in A1's history.
    $redeem($this->tenants->a2, 2);
    $this->context->bypass(fn () => CardEnrollment::query()->whereKey($this->enrollment->id)->update(['current_stamps' => 4]));
    $customer = ($this->summarised)();
    $site = $this->tenants->locationOf($this->tenants->a1)->name;

    ($this->open)($customer)->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('business/customers/show')
        ->where('customer', ['id' => $customer->id, 'name' => 'Salma T.', 'status' => 'active', 'visits' => 3, 'usualGapDays' => 4, 'lastVisitDays' => 4, 'rewardsRedeemed' => 1])
        ->where('cards', [['id' => $this->enrollment->id, 'name' => $this->tenants->cardA->name, 'rewardText' => $this->tenants->cardA->reward_text, 'stamps' => 4, 'toReward' => $this->tenants->cardA->stamps_required - 4]])
        ->where('history', [
            ['key' => 'redeemed-'.$reward->id, 'kind' => 'redeemed', 'source' => null, 'qty' => 0, 'card' => $reward->reward_text, 'location' => $site, 'by' => 'Yasmine A.', 'reason' => null, 'byScan' => false, 'at' => ['day' => 'other', 'date' => '2026-10-30', 'time' => '18:00']],
            ['key' => 'stamp-'.$takenBack->id, 'kind' => 'stamp', 'source' => 'correction', 'qty' => -1, 'card' => $this->tenants->cardA->name, 'location' => $site, 'by' => 'Yasmine A.', 'reason' => 'Given twice', 'byScan' => false, 'at' => ['day' => 'other', 'date' => '2026-10-28', 'time' => '10:01']],
            ['key' => 'stamp-'.$byHand->id, 'kind' => 'stamp', 'source' => 'manual', 'qty' => 1, 'card' => $this->tenants->cardA->name, 'location' => $site, 'by' => 'Yasmine A.', 'reason' => 'Card forgotten', 'byScan' => false, 'at' => ['day' => 'other', 'date' => '2026-10-28', 'time' => '10:00']],
            ['key' => 'stamp-'.$scan->id, 'kind' => 'stamp', 'source' => 'qr', 'qty' => 1, 'card' => $this->tenants->cardA->name, 'location' => $site, 'by' => 'Yasmine A.', 'reason' => null, 'byScan' => false, 'at' => ['day' => 'other', 'date' => '2026-10-25', 'time' => '10:00']],
            ['key' => 'stamp-'.$tap->id, 'kind' => 'stamp', 'source' => 'nfc', 'qty' => 1, 'card' => $this->tenants->cardA->name, 'location' => $site, 'by' => null, 'reason' => null, 'byScan' => false, 'at' => ['day' => 'other', 'date' => '2026-10-20', 'time' => '10:00']],
        ])
        ->where('shown', DescribeCustomer::SHOWN)
        ->where('more', false));
});

it('lists a redemption above the stamp it followed in the same second, as a scan does', function (): void {
    $stamp = ($this->stampAt)('2026-10-30 14:00:00', StampSource::Qr, values: ['staff_id' => $this->staff->id]);
    $reward = $this->context->bypass(fn (): Reward => tap(Reward::factory()->for($this->enrollment, 'enrollment')->create(), fn (Reward $reward) => $reward->forceFill([
        'status' => RewardStatus::Redeemed, 'redeemed_at' => '2026-10-30 14:00:00', 'redeemed_by' => $this->staff->id, 'redeemed_by_scan' => true,
        'redeemed_business_id' => $this->tenants->a1->id, 'redeemed_location_id' => $this->tenants->locationOf($this->tenants->a1)->id,
    ])->save()));

    ($this->open)(($this->summarised)())->assertInertia(fn (Assert $page): Assert => $page
        ->where('history.0.key', 'redeemed-'.$reward->id)
        ->where('history.0.byScan', true)
        ->where('history.1.key', 'stamp-'.$stamp->id));
});

it('shows 20 events, then 20 more on asking', function (): void {
    foreach (range(1, DescribeCustomer::SHOWN + 5) as $hour) {
        ($this->stampAt)(Carbon::parse('2026-10-01 00:00:00')->addHours($hour)->toDateTimeString());
    }
    $customer = ($this->summarised)();

    ($this->open)($customer)->assertInertia(fn (Assert $page): Assert => $page->has('history', DescribeCustomer::SHOWN)->where('more', true)
        ->where('history.0.at.time', '05:00'));
    ($this->open)($customer, ['shown' => 40])->assertInertia(fn (Assert $page): Assert => $page->has('history', DescribeCustomer::SHOWN + 5)->where('more', false));
    ($this->open)($customer, ['shown' => 5])->assertSessionHasErrors('shown');
});

it('never opens another business\'s customer, a sister franchisee\'s included', function (Closure $other): void {
    $customer = $other->call($this);

    ($this->open)($customer)->assertNotFound();
})->with([
    'a sister franchisee\'s' => fn (): BusinessCustomer => (function (): BusinessCustomer {
        ($this->stampAt)('2026-10-29 06:00:00', business: $this->tenants->a2);

        return ($this->summarised)($this->tenants->a2);
    })(),
    'another organization\'s' => fn (): BusinessCustomer => (function (): BusinessCustomer {
        $this->tenants->stamp($this->tenants->enroll($this->user, $this->tenants->cardB), $this->tenants->b1);
        app(SummariseCustomer::class)->handle($this->tenants->b1->id, $this->tenants->orgB->id, $this->user->id);

        return $this->context->bypass(fn (): BusinessCustomer => BusinessCustomer::query()->where('business_id', $this->tenants->b1->id)->sole());
    })(),
]);

it('keeps a customer from staff', function (): void {
    ($this->stampAt)('2026-10-29 06:00:00');

    ($this->open)(($this->summarised)(), user: $this->staff)->assertForbidden();
});
