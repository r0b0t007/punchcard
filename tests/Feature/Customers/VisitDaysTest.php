<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Actions\Customers\SummariseCustomer;
use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRequest;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CustomerVisitDay;
use App\Models\Location;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The days each customer came in to each site (CHW-45, B3)
|--------------------------------------------------------------------------
|
| One row per customer, site and local day with a presence stamp there,
| written with the customer's summary. Corrections and system stamps are
| not visits; a day once seen stays. In a franchise, a visit to A2 never
| makes an A1 day.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = User::factory()->create();
    $this->enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    // A fixed offset: Morocco's differs between timezone databases.
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save());

    $this->stampAt = fn (string $at, ?Business $business = null, StampSource $source = StampSource::Qr, int $qty = 1, array $overrides = []): StampEvent => $this->tenants
        ->stamp($this->enrollment, $business ?? $this->tenants->a1, [
            'source' => $source, 'qty' => $qty, 'created_at' => Carbon::parse($at),
            ...in_array($source, [StampSource::Manual, StampSource::Correction], true) ? ['reason' => 'Card forgotten'] : [],
            ...$source === StampSource::Bonus ? ['staff_id' => null, 'idempotency_key' => null] : [],
            ...$overrides,
        ]);
    $this->summarise = fn (?Business $business = null) => app(SummariseCustomer::class)
        ->handle(($business ?? $this->tenants->a1)->id, $this->tenants->orgA->id, $this->customer->id);
    // Each of the customer's days: [business, site, day, latest stamp].
    $this->days = fn (): array => $this->context->bypass(fn (): array => CustomerVisitDay::query()
        ->where('user_id', $this->customer->id)->orderBy('business_id')->orderBy('location_id')->orderBy('visited_on')->get()
        ->map(fn (CustomerVisitDay $day): array => [$day->business_id, $day->location_id, $day->visited_on->toDateString(), $day->last_at->toDateTimeString()])
        ->all());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('keeps one day per site in the site\'s time, with its latest stamp', function (): void {
    $a1 = $this->tenants->locationOf($this->tenants->a1)->id;
    // 12:00 and 23:00 in Dubai: one day. 00:30 the next day in Dubai is still 30 Oct in UTC.
    ($this->stampAt)('2026-10-30 08:00:00');
    ($this->stampAt)('2026-10-30 19:00:00', qty: 2);
    ($this->stampAt)('2026-10-30 20:30:00');
    ($this->summarise)();

    expect(($this->days)())->toBe([
        [$this->tenants->a1->id, $a1, '2026-10-30', '2026-10-30 19:00:00'],
        [$this->tenants->a1->id, $a1, '2026-10-31', '2026-10-30 20:30:00'],
    ]);
});

it('keeps a day for each site visited that day', function (): void {
    $terrace = $this->context->bypass(fn (): Location => Location::factory()->create(['business_id' => $this->tenants->a1->id, 'timezone' => 'Asia/Dubai']));
    ($this->stampAt)('2026-10-30 08:00:00');
    ($this->stampAt)('2026-10-30 10:00:00', overrides: ['location_id' => $terrace->id]);
    ($this->summarise)();

    expect(array_column(($this->days)(), 1))->toBe([$this->tenants->locationOf($this->tenants->a1)->id, $terrace->id])
        ->and(array_column(($this->days)(), 2))->toBe(['2026-10-30', '2026-10-30']);
});

it('never counts corrections or system stamps as visits, and keeps a day whatever was taken back', function (): void {
    ($this->stampAt)('2026-10-28 10:00:00', source: StampSource::Bonus);
    ($this->stampAt)('2026-10-29 10:00:00', source: StampSource::Manual);
    ($this->stampAt)('2026-10-30 10:00:00', source: StampSource::Correction, qty: -1);
    ($this->summarise)();

    // Everything given here was taken back: no longer a customer, but the visit happened.
    expect(array_column(($this->days)(), 2))->toBe(['2026-10-29']);
});

it('never makes a day at a franchisee from a visit to its sister', function (): void {
    ($this->stampAt)('2026-10-30 10:00:00', $this->tenants->a2);
    ($this->summarise)();

    expect(($this->days)())->toBe([]);

    ($this->summarise)($this->tenants->a2);

    expect(array_column(($this->days)(), 0))->toBe([$this->tenants->a2->id]);
});

it('writes the same days however often it runs', function (): void {
    ($this->stampAt)('2026-10-30 10:00:00');
    ($this->summarise)();
    ($this->summarise)();
    ($this->stampAt)('2026-10-30 11:00:00');
    ($this->summarise)();

    expect(($this->days)())->toHaveCount(1)
        ->and(($this->days)()[0][3])->toBe('2026-10-30 11:00:00');
});

it('moves a visit to its new day when the site\'s timezone changes, never counting it twice', function (): void {
    // 23:30 UTC: 30 Oct in UTC, 31 Oct in Dubai.
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'UTC'])->save());
    ($this->stampAt)('2026-10-30 23:30:00');
    ($this->summarise)();
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save());

    $this->artisan('punchcard:customers:summarise')->assertSuccessful();

    expect(array_column(($this->days)(), 2))->toBe(['2026-10-31']);
});

it('writes only the days that changed', function (): void {
    ($this->stampAt)('2026-10-29 10:00:00');
    ($this->stampAt)('2026-10-30 10:00:00');
    ($this->summarise)();
    // The writes to the visit days while summarising.
    $writes = function (): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->summarise)();
        DB::disableQueryLog();

        return array_values(array_filter(DB::getQueryLog(), fn (array $query): bool => preg_match('/^(insert|update|delete)\b.*customer_visit_days/i', $query['query']) === 1));
    };

    expect($writes())->toBe([]);

    ($this->stampAt)('2026-10-30 11:00:00');
    $written = $writes();

    expect($written)->toHaveCount(1)
        // One day's row: six bindings.
        ->and($written[0]['bindings'])->toHaveCount(6);
});

it('writes every day of years of visits', function (): void {
    foreach (range(0, 159) as $day) {
        ($this->stampAt)(Carbon::parse('2026-01-01 08:00:00')->addDays($day)->toDateTimeString());
    }
    ($this->summarise)();

    expect(($this->days)())->toHaveCount(160);
});

it('records the day after each stamp, through the queue', function (): void {
    $location = $this->tenants->locationOf($this->tenants->a1);
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);

    $this->context->bypass(fn () => app(AddStamps::class)->handle($this->enrollment, StampRequest::manual($location, $staff, (string) Str::uuid(), 'Card forgotten', 1)));

    expect(array_column(($this->days)(), 2))->toBe(['2026-11-01']);
});

it('keeps the days of a deleted account, as the ledger keeps its stamps', function (): void {
    ($this->stampAt)('2026-10-30 10:00:00');
    ($this->summarise)();

    app(DeleteAccount::class)->handle($this->customer);

    expect(($this->days)())->toHaveCount(1);
});

it('fills the days of who came in before', function (): void {
    ($this->stampAt)('2026-10-30 10:00:00');
    ($this->stampAt)('2026-10-30 10:00:00', $this->tenants->a2);

    $this->artisan('punchcard:customers:summarise')->assertSuccessful();

    expect(array_column(($this->days)(), 0))->toBe([$this->tenants->a1->id, $this->tenants->a2->id]);
});
