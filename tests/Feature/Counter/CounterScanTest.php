<?php

declare(strict_types=1);

use App\Actions\Counter\DescribeScan;
use App\Actions\Counter\RedeemScan;
use App\Actions\Counter\ScanMemberQr;
use App\Actions\Counter\ScanRefusal;
use App\Actions\Counter\ScanRefused;
use App\Actions\Counter\StampScan;
use App\Actions\MemberQr\MemberQrRefusal;
use App\Actions\MemberQr\MemberQrRefused;
use App\Actions\Rewards\RedeemRefused;
use App\Actions\Stamps\StampRejected;
use App\Actions\Stamps\StampResult;
use App\Enums\BusinessRole;
use App\Enums\RedeemRefusal;
use App\Enums\RewardStatus;
use App\Enums\StampRejection;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\CounterScan;
use App\Models\Location;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\MemberQr\MemberQrToken;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Staff scanning the member QR (CHW-30)
|--------------------------------------------------------------------------
|
| Staff scan a customer's member QR, or type its short code: the code is
| checked (MemberQrScan) and becomes a scan record, theirs for two minutes at
| one location. It stamps once (AddStamps, source qr, the staff device's
| idempotency key, the customer enrolled if new here) and proves presence to
| redeem (RedeemReward, without the customer's redeem window).
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-17 10:00:05');

    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = User::factory()->create(['name' => 'Salma Bennani']);
    $this->a1Staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->a2Staff = $this->tenants->member(User::factory()->create(), $this->tenants->a2);
    $this->a1Location = $this->tenants->locationOf($this->tenants->a1);
    $this->a2Location = $this->tenants->locationOf($this->tenants->a2);

    // Works as the staff member of the location's business, as their requests do.
    $this->as = function (User $staff, Business $business, BusinessRole $role = BusinessRole::Staff): void {
        $this->context->set($business->organization_id === $this->tenants->orgA->id ? $this->tenants->orgA : $this->tenants->orgB, $business, businessRole: $role);
    };
    $this->scan = function (?string $code = null, ?User $staff = null, ?Location $at = null): CounterScan {
        $at ??= $this->a1Location;
        $staff ??= $at->business_id === $this->tenants->a2->id ? $this->a2Staff : $this->a1Staff;
        ($this->as)($staff, $at->business_id === $this->tenants->a2->id ? $this->tenants->a2 : $this->tenants->a1);

        return app(ScanMemberQr::class)->handle($staff, $at, $code ?? MemberQrToken::issue($this->customer)['token']);
    };
    $this->stamp = fn (CounterScan $scan, int $qty = 1, string $key = 'key-1', ?User $staff = null): StampResult => app(StampScan::class)
        ->handle($scan, $staff ?? $this->a1Staff, $qty, $key);
    $this->reason = function (Closure $act): mixed {
        try {
            $act();
        } catch (MemberQrRefused|ScanRefused $refused) {
            return $refused->reason;
        } catch (StampRejected $rejected) {
            return $rejected->rejection;
        } catch (RedeemRefused $refused) {
            return $refused->refusal;
        }

        return null;
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('records a scan of the customer\'s code: who, where, by whom, until when', function (): void {
    $scan = ($this->scan)();

    expect($scan->user_id)->toBe($this->customer->id)
        ->and($scan->business_id)->toBe($this->tenants->a1->id)
        ->and($scan->location_id)->toBe($this->a1Location->id)
        ->and($scan->staff_id)->toBe($this->a1Staff->id)
        ->and($scan->member_qr_window)->toBe(MemberQrToken::issue($this->customer)['window'])
        ->and($scan->scanned_at->equalTo(now()))->toBeTrue()
        ->and($scan->expires_at->equalTo(now()->addSeconds(CounterScan::SECONDS)))->toBeTrue()
        ->and($scan->stamp_event_id)->toBeNull()
        ->and(strlen((string) $scan->getKey()))->toBe(26);
});

it('takes the short code typed in place of the QR', function (): void {
    expect(($this->scan)(MemberQrToken::shortCode($this->customer))->user_id)->toBe($this->customer->id);
});

it('refuses a code that does not count', function (string $how, MemberQrRefusal $reason): void {
    $token = MemberQrToken::issue($this->customer)['token'];

    $code = match ($how) {
        'not a code' => 'hello',
        'forged' => substr($token, 0, -2).'xx',
        'expired' => $token,
        'a deleted account' => $token,
    };

    if ($how === 'expired') {
        $this->travel(60)->seconds();
    }

    if ($how === 'a deleted account') {
        $this->customer->forceFill(['anonymised_at' => now()])->save();
    }

    expect(($this->reason)(fn () => ($this->scan)($code)))->toBe($reason)
        ->and($this->context->bypass(fn (): int => CounterScan::query()->count()))->toBe(0);
})->with([
    'not a code' => ['not a code', MemberQrRefusal::Malformed],
    'forged' => ['forged', MemberQrRefusal::Forged],
    'expired' => ['expired', MemberQrRefusal::Expired],
    'a deleted account' => ['a deleted account', MemberQrRefusal::UnknownUser],
]);

it('takes a code once at a business, even with the cache cleared, and again at a franchise sibling', function (): void {
    $token = MemberQrToken::issue($this->customer)['token'];
    ($this->scan)($token);

    expect(($this->reason)(fn () => ($this->scan)($token)))->toBe(MemberQrRefusal::Replayed);

    // The scan's own unique key holds without the cache's claim.
    Cache::flush();
    expect(($this->reason)(fn () => ($this->scan)($token)))->toBe(MemberQrRefusal::Replayed);

    expect(($this->scan)($token, at: $this->a2Location)->business_id)->toBe($this->tenants->a2->id);
});

it('scans only for staff who work at the location', function (): void {
    $elsewhere = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create());
    $limited = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->context->bypass(fn () => $this->tenants->a1->members()->updateExistingPivot($limited->id, ['location_id' => $elsewhere->id]));

    expect(fn () => ($this->scan)(staff: $limited))->toThrow(LogicException::class, 'staff')
        ->and(fn () => ($this->scan)(staff: $this->a2Staff))->toThrow(LogicException::class);
});

it('stamps the scanned customer on the card here, enrolling a customer new here', function (): void {
    $result = ($this->stamp)(($this->scan)(), 3, 'key-1');

    $enrollment = $this->context->bypass(fn (): CardEnrollment => CardEnrollment::query()->where('user_id', $this->customer->id)->sole());

    expect($enrollment->card_id)->toBe($this->tenants->cardA->id)
        ->and($result->replayed)->toBeFalse()
        ->and($result->event->source)->toBe(StampSource::Qr)
        ->and($result->event->qty)->toBe(3)
        ->and($result->event->staff_id)->toBe($this->a1Staff->id)
        ->and($result->event->location_id)->toBe($this->a1Location->id)
        ->and($result->event->idempotency_key)->toBe('key-1')
        ->and($result->enrollment->current_stamps)->toBe(3)
        ->and(CounterScan::query()->sole()->stamp_event_id)->toBe($result->event->id);
});

it('stamps once per scan: the same key gives the same stamp, also after the scan ran out', function (): void {
    $scan = ($this->scan)();
    $first = ($this->stamp)($scan, 2, 'key-1');

    $again = ($this->stamp)($scan, 2, 'key-1');
    expect($again->replayed)->toBeTrue()->and($again->event->id)->toBe($first->event->id);

    $this->travel(CounterScan::SECONDS + 1)->seconds();
    expect(($this->stamp)($scan, 2, 'key-1')->event->id)->toBe($first->event->id)
        ->and(($this->reason)(fn () => ($this->stamp)($scan, 2, 'key-2')))->toBe(ScanRefusal::Used)
        ->and($this->context->bypass(fn (): int => (int) CardEnrollment::query()->sole()->current_stamps))->toBe(2);
});

it('refuses a scan that ran out, or another staff member\'s', function (): void {
    $scan = ($this->scan)();
    $colleague = $this->tenants->member(User::factory()->create(), $this->tenants->a1);

    expect(($this->reason)(fn () => ($this->stamp)($scan, staff: $colleague)))->toBe(ScanRefusal::NotYours);

    $this->travel(CounterScan::SECONDS + 1)->seconds();
    expect(($this->reason)(fn () => ($this->stamp)($scan)))->toBe(ScanRefusal::Expired);
});

it('leaves the scan unused when the stamp is refused', function (): void {
    $enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->tenants->stamp($enrollment, $this->tenants->a1);
    $this->context->bypass(fn () => $enrollment->forceFill(['last_stamp_at' => now()])->save());
    $scan = ($this->scan)();

    expect(($this->reason)(fn () => ($this->stamp)($scan)))->toBe(StampRejection::Cooldown)
        ->and($scan->fresh()?->stamp_event_id)->toBeNull();
});

it('refuses a key already used for another stamp', function (): void {
    ($this->stamp)(($this->scan)(), 1, 'key-1');

    // A visit later, a new code and a new scan: the staff device must not reuse its key.
    $this->travel(2)->hours();
    $later = ($this->scan)();

    expect(($this->reason)(fn () => ($this->stamp)($later, 1, 'key-1')))->toBe(StampRejection::IdempotencyConflict)
        ->and($later->fresh()?->stamp_event_id)->toBeNull();
});

it('refuses to stamp where no card runs', function (): void {
    $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['active' => false])->save());

    expect(($this->reason)(fn () => ($this->stamp)(($this->scan)())))->toBe(StampRejection::CardInactive);
});

it('redeems a reward of the scanned customer without the customer\'s redeem window', function (): void {
    $reward = $this->tenants->reward($this->tenants->enroll($this->customer, $this->tenants->cardA));
    $scan = ($this->scan)();

    $result = app(RedeemScan::class)->handle($scan, $this->a1Staff, $reward);

    expect($result->redeemedNow)->toBeTrue()
        ->and($result->reward->status)->toBe(RewardStatus::Redeemed)
        ->and($result->reward->redeemed_by)->toBe($this->a1Staff->id)
        ->and($result->reward->redeemed_business_id)->toBe($this->tenants->a1->id)
        ->and($result->reward->redeemed_location_id)->toBe($this->a1Location->id)
        ->and(app(RedeemScan::class)->handle($scan, $this->a1Staff, $reward)->redeemedNow)->toBeFalse();
});

it('redeems only the scanned customer\'s reward, honoured here, on a scan still running', function (): void {
    $someoneElse = $this->tenants->reward($this->tenants->enroll(User::factory()->create(), $this->tenants->cardA));
    $elsewhere = $this->tenants->reward($this->tenants->enroll($this->customer, $this->tenants->cardB));
    $theirs = $this->tenants->reward($this->tenants->enroll($this->customer, $this->tenants->cardA));
    $scan = ($this->scan)();
    $redeem = fn (Reward $reward, ?User $staff = null) => app(RedeemScan::class)->handle($scan, $staff ?? $this->a1Staff, $reward);

    expect(($this->reason)(fn () => $redeem($someoneElse)))->toBe(RedeemRefusal::NotYours)
        ->and(($this->reason)(fn () => $redeem($elsewhere)))->toBe(RedeemRefusal::NotHonoured)
        ->and(($this->reason)(fn () => $redeem($theirs, $this->tenants->member(User::factory()->create(), $this->tenants->a1))))->toBe(ScanRefusal::NotYours);

    $this->travel(CounterScan::SECONDS + 1)->seconds();
    expect(($this->reason)(fn () => $redeem($theirs)))->toBe(ScanRefusal::Expired)
        ->and($this->context->bypass(fn () => $theirs->fresh()?->status))->toBe(RewardStatus::Available);
});

it('keeps a scan inside its business: no franchise sibling, no other organization', function (): void {
    $scan = ($this->scan)();
    $b1Staff = $this->tenants->member(User::factory()->create(), $this->tenants->b1);

    $reward = $this->tenants->reward($this->tenants->enroll($this->customer, $this->tenants->cardA));

    ($this->as)($this->a2Staff, $this->tenants->a2);
    expect(CounterScan::query()->whereKey($scan->id)->exists())->toBeFalse()
        ->and(fn () => ($this->stamp)($scan, staff: $this->a2Staff))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RedeemScan::class)->handle($scan, $this->a2Staff, $reward))->toThrow(ModelNotFoundException::class);

    ($this->as)($b1Staff, $this->tenants->b1);
    expect(CounterScan::query()->whereKey($scan->id)->exists())->toBeFalse()
        ->and(fn () => ($this->stamp)($scan, staff: $b1Staff))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RedeemScan::class)->handle($scan, $b1Staff, $reward))->toThrow(ModelNotFoundException::class)
        ->and($this->context->bypass(fn (): int => StampEvent::query()->count()))->toBe(0)
        ->and($this->context->bypass(fn () => $reward->fresh()?->status))->toBe(RewardStatus::Available);

    ($this->as)($this->a1Staff, $this->tenants->a1);
    expect(CounterScan::query()->whereKey($scan->id)->exists())->toBeTrue();
});

it('describes a scan for the result screen (S2)', function (): void {
    $scan = ($this->scan)();
    $new = app(DescribeScan::class)->handle($scan);

    expect($new['firstName'])->toBe('Salma')
        ->and($new['card'])->toMatchArray(['name' => 'A card', 'stamps' => 0, 'required' => 10, 'new' => true])
        ->and($new['rewards'])->toBe([])
        ->and($new['used'])->toBeFalse()
        ->and($new['secondsLeft'])->toBe(CounterScan::SECONDS);

    ($this->stamp)($scan, 3);
    $reward = $this->tenants->reward($this->context->bypass(fn (): CardEnrollment => CardEnrollment::query()->sole()));
    $stamped = app(DescribeScan::class)->handle($scan->fresh() ?? $scan);

    expect($stamped['card'])->toMatchArray(['stamps' => 3, 'new' => false])
        ->and($stamped['used'])->toBeTrue()
        ->and($stamped['rewards'])->toBe([['id' => $reward->id, 'text' => $reward->reward_text]]);
});

it('enrolls nobody when the first stamp of a customer new here is refused', function (): void {
    $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['daily_cap' => 2])->save());
    $scan = ($this->scan)();

    expect(($this->reason)(fn () => ($this->stamp)($scan, 3)))->toBe(StampRejection::DailyCap)
        ->and($this->context->bypass(fn (): int => CardEnrollment::query()->where('user_id', $this->customer->id)->count()))->toBe(0)
        ->and($scan->fresh()?->stamp_event_id)->toBeNull()
        ->and(($this->stamp)($scan, 2)->event->qty)->toBe(2);
});

it('refuses a retry with the same key but another quantity', function (): void {
    $scan = ($this->scan)();
    ($this->stamp)($scan, 2, 'key-1');

    expect(($this->reason)(fn () => ($this->stamp)($scan, 3, 'key-1')))->toBe(StampRejection::IdempotencyConflict);
});

it('stamps and redeems on the shared card at a franchise sibling, recorded there', function (): void {
    $enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $reward = $this->tenants->reward($enrollment);
    $scan = ($this->scan)(at: $this->a2Location);

    $stamp = ($this->stamp)($scan, staff: $this->a2Staff);
    $redeemed = app(RedeemScan::class)->handle($scan, $this->a2Staff, $reward);

    expect($stamp->event->enrollment_id)->toBe($enrollment->id)
        ->and($stamp->event->business_id)->toBe($this->tenants->a2->id)
        ->and($stamp->event->location_id)->toBe($this->a2Location->id)
        ->and($redeemed->reward->redeemed_business_id)->toBe($this->tenants->a2->id)
        ->and($redeemed->reward->redeemed_location_id)->toBe($this->a2Location->id);
});

it('lets an owner scan anywhere in the business', function (): void {
    $owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);

    expect(($this->scan)(staff: $owner)->staff_id)->toBe($owner->id);
});

it('takes a short code once at a business, even with the cache cleared', function (): void {
    $code = MemberQrToken::shortCode($this->customer);
    ($this->scan)($code);
    Cache::flush();

    expect(($this->reason)(fn () => ($this->scan)($code)))->toBe(MemberQrRefusal::Replayed);
});

it('keeps the caller\'s own transaction usable when a code was already scanned', function (): void {
    $token = MemberQrToken::issue($this->customer)['token'];
    ($this->scan)($token);
    Cache::flush();

    // On Postgres a unique violation outside a savepoint aborts the whole transaction.
    $after = DB::transaction(function () use ($token): int {
        expect(($this->reason)(fn () => ($this->scan)($token)))->toBe(MemberQrRefusal::Replayed);

        return CounterScan::query()->count();
    });

    expect($after)->toBe(1);
});

it('gives the same redemption to a retry after the scan ran out, and nothing else', function (): void {
    $enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $reward = $this->tenants->reward($enrollment);
    $other = $this->context->bypass(fn (): Reward => Reward::factory()->for($enrollment, 'enrollment')->create(['milestone' => 2]));
    $scan = ($this->scan)();
    app(RedeemScan::class)->handle($scan, $this->a1Staff, $reward);

    $this->travel(CounterScan::SECONDS + 1)->seconds();

    $again = app(RedeemScan::class)->handle($scan, $this->a1Staff, $reward);
    expect($again->redeemedNow)->toBeFalse()
        ->and($again->reward->id)->toBe($reward->id)
        ->and(($this->reason)(fn () => app(RedeemScan::class)->handle($scan, $this->a1Staff, $other)))->toBe(ScanRefusal::Expired);
});

it('refuses after the scan ran out a reward redeemed some other way', function (string $how): void {
    $reward = $this->tenants->reward($this->tenants->enroll($this->customer, $this->tenants->cardA));
    $scan = ($this->scan)();
    $elsewhere = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create());

    $this->context->bypass(fn () => $reward->forceFill([
        'status' => RewardStatus::Redeemed,
        'redeemed_at' => $how === 'earlier, by the same staff member' ? now()->subHour() : now(),
        'redeemed_by' => $how === 'by someone else' ? null : $this->a1Staff->id,
        'redeemed_business_id' => $this->tenants->a1->id,
        'redeemed_location_id' => $how === 'at another site' ? $elsewhere->id : $this->a1Location->id,
    ])->save());

    $this->travel(CounterScan::SECONDS + 1)->seconds();

    expect(($this->reason)(fn () => app(RedeemScan::class)->handle($scan, $this->a1Staff, $reward)))->toBe(ScanRefusal::Expired);
})->with(['earlier, by the same staff member', 'by someone else', 'at another site']);

it('prunes scans a day after they ran out', function (): void {
    $old = ($this->scan)();
    $this->travel(1)->days();
    $this->travel(CounterScan::SECONDS + 1)->seconds();
    $recent = ($this->scan)();

    $this->artisan('model:prune', ['--model' => [CounterScan::class]])->assertSuccessful();

    expect($this->context->bypass(fn (): array => CounterScan::query()->pluck('id')->all()))->toBe([$recent->id]);
});
