<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Actions\Counter\RedeemScan;
use App\Actions\Counter\ScanMemberQr;
use App\Actions\Customers\SummariseCustomer;
use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRequest;
use App\Enums\CustomerSegment;
use App\Enums\CustomerStatus;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Events\EnrollmentChanged;
use App\Listeners\SummariseCustomers;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\MemberQr\MemberQrToken;
use App\Support\Tenancy\TenantContext;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A business's customers, summarised from the ledger (CHW-44, B4)
|--------------------------------------------------------------------------
|
| A visit is a day with a presence stamp at the business, in the site's
| time. Going quiet after twice the usual gap (at least 14 days), lost after
| 60, new within 30 days, a regular from 3 visits. In a franchise, a visit
| to A2 never makes an A1 customer.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = User::factory()->create(['name' => 'Salma Tazi']);
    $this->enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    // A fixed offset: Morocco's differs between timezone databases.
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save());

    $this->stampAt = fn (string $at, ?Business $business = null, StampSource $source = StampSource::Qr, int $qty = 1): StampEvent => $this->tenants
        ->stamp($this->enrollment, $business ?? $this->tenants->a1, [
            'source' => $source, 'qty' => $qty, 'created_at' => Carbon::parse($at),
            // Staff give a reason for a stamp by hand and a correction.
            ...in_array($source, [StampSource::Manual, StampSource::Correction], true) ? ['reason' => 'Card forgotten'] : [],
        ]);
    $this->summarise = fn (?Business $business = null, ?User $user = null) => app(SummariseCustomer::class)
        ->handle(($business ?? $this->tenants->a1)->id, $this->tenants->orgA->id, ($user ?? $this->customer)->id);
    $this->row = fn (?Business $business = null, ?User $user = null): ?BusinessCustomer => $this->context->bypass(fn () => BusinessCustomer::query()
        ->where('business_id', ($business ?? $this->tenants->a1)->id)->where('user_id', ($user ?? $this->customer)->id)->first());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('counts a visit per day in the site\'s time, whatever the stamps that day', function (): void {
    // 12:00 and 23:00 in Dubai: one day. 00:30 the next day in Dubai is still 30 Oct in UTC.
    ($this->stampAt)('2026-10-30 08:00:00');
    ($this->stampAt)('2026-10-30 19:00:00', qty: 2);
    ($this->stampAt)('2026-10-30 20:30:00');
    ($this->summarise)();

    expect(($this->row)())
        ->visits->toBe(2)
        ->first_visit_at->toDateTimeString()->toBe('2026-10-30 08:00:00')
        ->last_visit_at->toDateTimeString()->toBe('2026-10-30 20:30:00')
        ->usual_gap_days->toBe(1);
});

it('never counts corrections or system stamps as visits', function (): void {
    ($this->stampAt)('2026-10-20 10:00:00', source: StampSource::Bonus);
    ($this->summarise)();

    expect(($this->row)())->toBeNull();

    ($this->stampAt)('2026-10-22 10:00:00', source: StampSource::Manual, qty: 2);
    ($this->stampAt)('2026-10-23 10:00:00', source: StampSource::Correction, qty: -1);
    ($this->stampAt)('2026-10-24 10:00:00', source: StampSource::Bonus);
    ($this->summarise)();

    expect(($this->row)()->visits)->toBe(1);
});

it('expects them back after twice their usual gap, never sooner than 14 days, and loses them after 60', function (): void {
    ($this->stampAt)('2026-10-01 10:00:00');
    ($this->summarise)();

    expect(($this->row)())
        ->usual_gap_days->toBeNull()
        ->quiet_at->toDateTimeString()->toBe('2026-10-15 10:00:00')
        ->lost_at->toDateTimeString()->toBe('2026-11-30 10:00:00');

    // Every 3 days: twice is 6, under the 14-day floor.
    ($this->stampAt)('2026-10-04 10:00:00');
    ($this->stampAt)('2026-10-07 10:00:00');
    ($this->summarise)();

    expect(($this->row)())->usual_gap_days->toBe(3)->quiet_at->toDateTimeString()->toBe('2026-10-21 10:00:00');

    // Days 1, 4, 7 and 26: 25 days over 3 gaps, 8.33 a gap, 17 days (rounded up) after the last.
    ($this->stampAt)('2026-10-26 10:00:00');
    ($this->summarise)();

    expect(($this->row)())
        ->visits->toBe(4)
        ->usual_gap_days->toBe(8)
        ->quiet_at->toDateTimeString()->toBe('2026-11-12 10:00:00')
        ->lost_at->toDateTimeString()->toBe('2026-12-25 10:00:00');
});

it('rounds their usual gap to whole days', function (): void {
    // Days 1, 4 and 6: 5 days over 2 gaps.
    foreach (['2026-10-01', '2026-10-04', '2026-10-06'] as $day) {
        ($this->stampAt)($day.' 10:00:00');
    }
    ($this->summarise)();

    expect(($this->row)()->usual_gap_days)->toBe(3);
});

it('summarises nothing for an enrollment named with another organization', function (): void {
    // Their card in organization B, with a visit to B1.
    $this->tenants->stamp($this->tenants->enroll($this->customer, $this->tenants->cardB), $this->tenants->b1);

    app(SummariseCustomers::class)->handle(new EnrollmentChanged($this->enrollment->id, $this->tenants->orgB->id, $this->tenants->b1->id, []));

    expect(($this->row)($this->tenants->b1))->toBeNull();
});

it('counts the rewards redeemed here, not at a franchise sister', function (): void {
    ($this->stampAt)('2026-10-30 10:00:00');
    $milestone = new stdClass;
    $milestone->last = 0;
    $redeem = fn (Business $at): int => $this->context->bypass(fn (): int => Reward::query()->whereKey(Reward::factory()->for($this->enrollment, 'enrollment')->create(['milestone' => ++$milestone->last])->id)->update([
        'status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $at->id, 'redeemed_location_id' => $this->tenants->locationOf($at)->id,
    ]));
    $redeem($this->tenants->a1);
    $redeem($this->tenants->a1);
    $redeem($this->tenants->a2);
    ($this->summarise)();

    expect(($this->row)()->rewards_redeemed)->toBe(2);
});

it('never makes a customer of a franchisee from a visit to its sister', function (): void {
    ($this->stampAt)('2026-10-30 10:00:00', $this->tenants->a2);
    ($this->summarise)($this->tenants->a1);
    ($this->summarise)($this->tenants->a2);

    expect(($this->row)($this->tenants->a1))->toBeNull()
        ->and(($this->row)($this->tenants->a2)->visits)->toBe(1);
});

it('keeps no customer behind a deleted account', function (): void {
    $location = $this->tenants->locationOf($this->tenants->a1);
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $add = fn (StampRequest $request) => $this->context->bypass(fn () => app(AddStamps::class)->handle($this->enrollment, $request));
    $add(StampRequest::manual($location, $staff, (string) Str::uuid(), 'Card forgotten', 2));
    expect(($this->row)())->not->toBeNull();

    app(DeleteAccount::class)->handle($this->customer);
    expect(($this->row)())->toBeNull();

    // A correction made for the account since summarises nothing back.
    $add(StampRequest::correction($location, $staff, (string) Str::uuid(), 'Given twice', -1));
    expect(($this->row)())->toBeNull();
});

it('summarises in the queue, never in the request that stamps', function (): void {
    Queue::fake();
    $location = $this->tenants->locationOf($this->tenants->a1);
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);

    $this->context->bypass(fn () => app(AddStamps::class)->handle($this->enrollment, StampRequest::manual($location, $staff, (string) Str::uuid(), 'Card forgotten', 1)));

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === SummariseCustomers::class);
    expect(($this->row)())->toBeNull();
});

it('counts the day in the local time of the site across a clock change', function (): void {
    // Paris leaves summer time on 25 Oct: 00:30 (UTC+2) and 23:30 (UTC+1) are the same day there.
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Europe/Paris'])->save());
    ($this->stampAt)('2026-10-24 22:30:00');
    ($this->stampAt)('2026-10-25 22:30:00');
    ($this->summarise)();

    expect(($this->row)()->visits)->toBe(1);
});

it('summarises after each stamp and redemption, through the queue', function (): void {
    $location = $this->tenants->locationOf($this->tenants->a1);
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);

    $this->context->bypass(fn () => app(AddStamps::class)->handle($this->enrollment, StampRequest::manual($location, $staff, (string) Str::uuid(), 'Card forgotten', 1)));
    expect(($this->row)()->visits)->toBe(1);

    $reward = $this->tenants->reward($this->enrollment);
    $this->context->set($this->tenants->orgA, $this->tenants->a1);
    $scan = app(ScanMemberQr::class)->handle($staff, $location, MemberQrToken::issue($this->customer)['token']);
    app(RedeemScan::class)->handle($scan, $staff, $reward);
    $this->context->clear();

    expect(($this->row)()->rewards_redeemed)->toBe(1);
});

it('sorts customers into the tabs as time goes by', function (): void {
    $other = User::factory()->create();
    $theirs = $this->tenants->enroll($other, $this->tenants->cardA);
    // Salma: days 1, 11 and 21 Oct, every 10 days, so quiet 20 days after the last.
    foreach (['2026-10-01', '2026-10-11', '2026-10-21'] as $day) {
        ($this->stampAt)($day.' 10:00:00');
    }
    // The other: one visit, on 4 Oct.
    $this->tenants->stamp($theirs, $this->tenants->a1, ['created_at' => Carbon::parse('2026-10-04 10:00:00')]);
    ($this->summarise)();
    ($this->summarise)(user: $other);

    $in = fn (CustomerSegment $segment): array => $this->context->bypass(fn (): array => BusinessCustomer::query()
        ->inSegment($segment, now())->orderBy('user_id')->pluck('user_id')->all());
    $tabs = fn (): array => array_map(fn (CustomerSegment $segment): array => $in($segment), CustomerSegment::cases());
    [$salma, $them] = [$this->customer->id, $other->id];

    // 1 Nov, 12:00: Salma 11 days since, a regular; the other 28 days since, quiet, and new (a first visit within 30 days).
    expect($tabs())->toBe([[$salma, $them], [$salma], [$them], [], [$them]])
        ->and(($this->row)()->status(now()))->toBe(CustomerStatus::Active);

    // 3 Nov, 10:00: the other's first visit 30 days ago, no longer new.
    $this->travelTo(Carbon::parse('2026-11-03 09:59:59'));
    expect($in(CustomerSegment::New))->toBe([$them]);
    $this->travelTo(Carbon::parse('2026-11-03 10:00:00'));
    expect($in(CustomerSegment::New))->toBe([]);

    // 10 Nov, 10:00: 20 days since Salma's last visit, she goes quiet.
    $this->travelTo(Carbon::parse('2026-11-10 09:59:59'));
    expect($in(CustomerSegment::Quiet))->toBe([$them]);
    $this->travelTo(Carbon::parse('2026-11-10 10:00:00'));
    expect($in(CustomerSegment::Quiet))->toBe([$salma, $them])
        ->and($in(CustomerSegment::Regulars))->toBe([])
        ->and(($this->row)()->status(now()))->toBe(CustomerStatus::Quiet);

    // 3 Dec, 10:00: 60 days since the other's visit, lost; no longer quiet.
    $this->travelTo(Carbon::parse('2026-12-03 10:00:00'));
    expect($in(CustomerSegment::Lost))->toBe([$them])
        ->and($in(CustomerSegment::Quiet))->toBe([$salma])
        ->and(($this->row)(user: $other)->status(now()))->toBe(CustomerStatus::Lost);
});

it('loses a customer who comes monthly or less without a quiet spell, and no longer counts them a regular', function (): void {
    // Every 40 days: twice is 80, past the 60 days that lose them.
    foreach (['2026-07-01', '2026-08-10', '2026-09-19'] as $day) {
        ($this->stampAt)($day.' 10:00:00');
    }
    ($this->summarise)();
    $in = fn (CustomerSegment $segment): array => $this->context->bypass(fn (): array => BusinessCustomer::query()->inSegment($segment, now())->pluck('user_id')->all());

    expect(($this->row)()->quiet_at->toDateTimeString())->toBe('2026-11-18 10:00:00')
        ->and(($this->row)()->lost_at->toDateTimeString())->toBe('2026-11-18 10:00:00')
        ->and($in(CustomerSegment::Regulars))->toBe([$this->customer->id]);

    $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));

    expect($in(CustomerSegment::Lost))->toBe([$this->customer->id])
        ->and($in(CustomerSegment::Regulars))->toBe([])
        ->and($in(CustomerSegment::Quiet))->toBe([]);
});

it('keeps no customer whose stamps here were all taken back', function (): void {
    // A stamp by hand for the wrong customer, corrected.
    ($this->stampAt)('2026-10-30 10:00:00', source: StampSource::Manual);
    ($this->stampAt)('2026-10-30 10:05:00', source: StampSource::Correction, qty: -1);
    ($this->summarise)();

    expect(($this->row)())->toBeNull();

    // A real visit of two stamps, one taken back: still a visit.
    ($this->stampAt)('2026-10-31 10:00:00', qty: 2);
    ($this->stampAt)('2026-10-31 10:05:00', source: StampSource::Correction, qty: -1);
    ($this->summarise)();

    expect(($this->row)()->visits)->toBe(2);
});

it('takes a regular from 3 visits', function (): void {
    ($this->stampAt)('2026-10-25 10:00:00');
    ($this->stampAt)('2026-10-28 10:00:00');
    ($this->summarise)();
    $regulars = fn (): array => $this->context->bypass(fn (): array => BusinessCustomer::query()->inSegment(CustomerSegment::Regulars, now())->pluck('user_id')->all());

    expect($regulars())->toBe([]);

    ($this->stampAt)('2026-10-31 10:00:00');
    ($this->summarise)();

    expect($regulars())->toBe([$this->customer->id]);
});

it('summarises who came in before, for every business or one', function (): void {
    $other = User::factory()->create();
    ($this->stampAt)('2026-10-30 10:00:00');
    ($this->stampAt)('2026-10-31 10:00:00');
    ($this->stampAt)('2026-10-30 10:00:00', $this->tenants->a2);
    $this->tenants->stamp($this->tenants->enroll($other, $this->tenants->cardB), $this->tenants->b1);
    // Only a bonus at A1: never came in.
    $this->tenants->stamp($this->tenants->enroll(User::factory()->create(), $this->tenants->cardA), $this->tenants->a1, ['source' => StampSource::Bonus]);
    $rows = fn (): array => $this->context->bypass(fn (): array => BusinessCustomer::query()->orderBy('business_id')->get()
        ->map(fn (BusinessCustomer $row): array => [$row->business_id, $row->user_id, $row->visits])->all());

    $this->artisan('punchcard:customers:summarise', ['--business' => $this->tenants->a2->id])->assertSuccessful();
    expect($rows())->toBe([[$this->tenants->a2->id, $this->customer->id, 1]]);

    $this->artisan('punchcard:customers:summarise')->expectsOutput(trans_choice(':count customer summarised.|:count customers summarised.', 3))->assertSuccessful();
    expect($rows())->toBe([
        [$this->tenants->a1->id, $this->customer->id, 2],
        [$this->tenants->a2->id, $this->customer->id, 1],
        [$this->tenants->b1->id, $other->id, 1],
    ]);
});

it('names a customer by first name and initial only', function (string $name, ?string $short): void {
    expect((new User(['name' => $name]))->shortName())->toBe($short);
})->with([
    ['Salma Tazi', 'Salma T.'],
    ['Salma Bint Ahmed Tazi', 'Salma T.'],
    ['سلمى تازي', 'سلمى ت.'],
    ['Salma', 'Salma'],
    ['  ', null],
]);
