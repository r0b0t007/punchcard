<?php

declare(strict_types=1);

use App\Actions\Counter\ScanMemberQr;
use App\Enums\BusinessRole;
use App\Enums\RewardStatus;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\CounterScan;
use App\Models\Location;
use App\Models\Reward;
use App\Models\User;
use App\Support\MemberQr\MemberQrToken;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Staff scanning pages (CHW-30, S2, S3)
|--------------------------------------------------------------------------
|
| The scanner at one of the sites the staff member works at, the result of
| their scan (S2: who, the card here, stamps, rewards), stamping and
| redeeming with it. Only their own scans, never another business's.
| The rules themselves (one scan per code, one stamp per scan, the redeem
| proof) are in CounterScanTest.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-17 10:00:05');
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = User::factory()->create(['name' => 'Salma Bennani']);
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $this->staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->here = $this->tenants->locationOf($this->tenants->a1);

    $this->as = fn (User $user, ?Business $business = null) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'business:'.($business ?? $this->tenants->a1)->id]);
    $this->scanAs = fn (User $user, ?string $code = null, ?Location $at = null, ?Business $business = null) => ($this->as)($user, $business)
        ->post(route('counter.scans.store'), ['code' => $code ?? MemberQrToken::issue($this->customer)['token'], 'location' => ($at ?? $this->here)->id]);
    $this->onlyScan = fn (): CounterScan => $this->context->bypass(fn (): CounterScan => CounterScan::query()->sole());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('opens the scanner at the sites the staff member works at', function (): void {
    $second = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create(['name' => 'Zz terrace']));
    $limited = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->context->bypass(fn () => $this->tenants->a1->members()->updateExistingPivot($limited->id, ['location_id' => $second->id]));

    ($this->as)($this->owner)->get(route('counter.scan'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('business/scan')
            ->where('locations', [['id' => $this->here->id, 'name' => $this->here->name], ['id' => $second->id, 'name' => 'Zz terrace']]));

    ($this->as)($limited)->get(route('counter.scan'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('locations', [['id' => $second->id, 'name' => 'Zz terrace']]));
});

it('keeps the scanner to people who work in a business', function (): void {
    $this->actingAs($this->customer)->get(route('counter.scan'))->assertForbidden();
});

it('scans a QR or a typed short code, then shows the result', function (string $how): void {
    $code = $how === 'qr' ? MemberQrToken::issue($this->customer)['token'] : MemberQrToken::shortCode($this->customer);

    ($this->scanAs)($this->staff, $code)->assertRedirect(route('counter.scans.show', ($this->onlyScan)()));

    expect(($this->onlyScan)()->location_id)->toBe($this->here->id);
})->with(['qr', 'short code']);

it('says on the field what to fix in a code', function (string $how, string $message): void {
    $code = match ($how) {
        'not a code' => 'hello',
        'wrong digits' => $this->customer->id.' 000000',
        'late' => MemberQrToken::issue($this->customer, now()->subMinutes(2))['token'],
    };

    ($this->scanAs)($this->staff, $code)->assertSessionHasErrors(['code' => $message]);
})->with([
    'not a code' => ['not a code', "That code doesn't match. Check the numbers, or scan the QR."],
    'wrong digits' => ['wrong digits', "That code doesn't match. Check the numbers, or scan the QR."],
    'late' => ['late', 'That code has run out. Ask for the one on screen now.'],
]);

it('shows in full a refusal that leaves nothing to retry with the code', function (): void {
    $token = MemberQrToken::issue($this->customer)['token'];
    ($this->scanAs)($this->staff, $token);

    ($this->scanAs)($this->staff, $token)->assertRedirect(route('counter.scan'))->assertInertiaFlash('scanRefusal', 'replayed');
    ($this->scanAs)($this->staff, MemberQrToken::issue($this->staff)['token'])->assertInertiaFlash('scanRefusal', 'own_code');

    foreach (range(1, ScanMemberQr::STAFF_WRONG_CODES) as $guess) {
        // A guess for a different member each time: past each member's own count, the staff member's.
        ($this->scanAs)($this->staff, (900000 + $guess).' 123456');
        $this->travel(4)->seconds();
    }

    ($this->scanAs)($this->staff, MemberQrToken::shortCode($this->customer))->assertInertiaFlash('scanRefusal', 'too_many_wrong_codes');
    // Typing is paused for them; their camera still scans.
    ($this->scanAs)($this->staff, MemberQrToken::issue(User::factory()->create())['token'])->assertRedirectContains('/business/counter/scans/');
});

it('scans only at a site the staff member works at', function (): void {
    $elsewhere = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create());
    $this->context->bypass(fn () => $this->tenants->a1->members()->updateExistingPivot($this->staff->id, ['location_id' => $this->here->id]));

    ($this->scanAs)($this->staff, at: $elsewhere)->assertNotFound();
    ($this->scanAs)($this->staff, at: $this->tenants->locationOf($this->tenants->a2))->assertNotFound();
    expect($this->context->bypass(fn (): int => CounterScan::query()->count()))->toBe(0);
});

it('scans at a counter\'s pace, never a script\'s', function (): void {
    foreach (range(1, 20) as $attempt) {
        ($this->scanAs)($this->staff, 'hello')->assertSessionHasErrors('code');
    }

    ($this->scanAs)($this->staff, 'hello')->assertTooManyRequests();
});

it('shows the staff member the result of their own scan, and nobody else', function (): void {
    ($this->scanAs)($this->staff);
    $scan = ($this->onlyScan)();
    $colleague = $this->tenants->member(User::factory()->create(), $this->tenants->a1);

    ($this->as)($this->staff)->get(route('counter.scans.show', $scan))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('business/scan-result')
            ->where('scan', $scan->id)
            ->where('firstName', 'Salma')
            ->where('card.new', true)
            ->where('card.paused', false)
            ->where('stamped', false)
            ->where('maxQty', 10));

    ($this->as)($colleague)->get(route('counter.scans.show', $scan))->assertForbidden();
    ($this->as)($this->tenants->member(User::factory()->create(), $this->tenants->a2), $this->tenants->a2)->get(route('counter.scans.show', $scan))->assertNotFound();
    ($this->as)($this->tenants->member(User::factory()->create(), $this->tenants->b1), $this->tenants->b1)->get(route('counter.scans.show', $scan))->assertNotFound();
});

it('stamps with the scan and says how it went', function (): void {
    ($this->scanAs)($this->staff);
    $scan = ($this->onlyScan)();

    ($this->as)($this->staff)->post(route('counter.scans.stamp', $scan), ['qty' => 2, 'key' => 'key-1'])
        ->assertRedirect(route('counter.scans.show', $scan))
        ->assertInertiaFlash('stampResult', ['state' => 'done', 'qty' => 2, 'rewards' => 0]);

    expect($this->context->bypass(fn (): int => (int) CardEnrollment::query()->where('user_id', $this->customer->id)->sole()->current_stamps))->toBe(2);

    ($this->as)($this->staff)->post(route('counter.scans.stamp', $scan), ['qty' => 1, 'key' => 'key-2'])
        ->assertInertiaFlash('stampResult', ['state' => 'used']);
});

it('says when the next stamp can be given, in the site\'s time, a day or more ahead too', function (int $cooldown, string $day): void {
    $this->context->bypass(fn () => $this->here->forceFill(['timezone' => 'Africa/Casablanca'])->save());
    $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['cooldown_min' => $cooldown])->save());
    $enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->tenants->stamp($enrollment, $this->tenants->a1);
    $this->context->bypass(fn () => $enrollment->forceFill(['last_stamp_at' => now()])->save());
    ($this->scanAs)($this->staff);
    $scan = ($this->onlyScan)();
    $next = now()->addMinutes($cooldown)->setTimezone('Africa/Casablanca');

    ($this->as)($this->staff)->post(route('counter.scans.stamp', $scan), ['qty' => 1, 'key' => 'key-1'])
        ->assertInertiaFlash('stampResult', ['state' => 'cooldown', 'availableAt' => ['day' => $day, 'date' => $next->format('Y-m-d'), 'time' => $next->format('H:i')]]);
})->with([
    'twenty minutes' => [20, 'today'],
    'a day' => [24 * 60, 'tomorrow'],
    'two days' => [48 * 60, 'later'],
]);

it('stamps 1 to 10, with the device\'s key, and only with the staff member\'s own scan', function (): void {
    ($this->scanAs)($this->staff);
    $scan = ($this->onlyScan)();
    $colleague = $this->tenants->member(User::factory()->create(), $this->tenants->a1);

    ($this->as)($this->staff)->post(route('counter.scans.stamp', $scan), ['qty' => 11, 'key' => 'key-1'])->assertSessionHasErrors('qty');
    ($this->as)($this->staff)->post(route('counter.scans.stamp', $scan), ['qty' => 1])->assertSessionHasErrors('key');
    ($this->as)($colleague)->post(route('counter.scans.stamp', $scan), ['qty' => 1, 'key' => 'key-1'])->assertForbidden();
    ($this->as)($this->tenants->member(User::factory()->create(), $this->tenants->b1), $this->tenants->b1)->post(route('counter.scans.stamp', $scan), ['qty' => 1, 'key' => 'key-1'])->assertNotFound();
});

it('redeems the scanned customer\'s reward, and finds no one else\'s', function (): void {
    $theirs = $this->tenants->reward($this->tenants->enroll($this->customer, $this->tenants->cardA));
    $someoneElse = $this->tenants->reward($this->tenants->enroll(User::factory()->create(), $this->tenants->cardA));
    ($this->scanAs)($this->staff);
    $scan = ($this->onlyScan)();

    // Another customer's reward is no reward here, whoever's it is.
    ($this->as)($this->staff)->post(route('counter.scans.redeem', [$scan, $someoneElse->id]))->assertNotFound();

    ($this->as)($this->staff)->post(route('counter.scans.redeem', [$scan, $theirs->id]))
        ->assertRedirect(route('counter.scans.show', $scan))
        ->assertInertiaFlash('redeemed.reward', $theirs->reward_text);

    expect($this->context->bypass(fn () => $theirs->fresh()?->status))->toBe(RewardStatus::Redeemed)
        ->and($this->context->bypass(fn () => $someoneElse->fresh()?->status))->toBe(RewardStatus::Available);

    ($this->as)($this->staff)->post(route('counter.scans.redeem', [$scan, 999999]))->assertNotFound();
});

it('redeems at a franchise sibling a reward earned at another of its businesses', function (): void {
    $reward = $this->tenants->reward($this->tenants->enroll($this->customer, $this->tenants->cardA));
    $a2Staff = $this->tenants->member(User::factory()->create(), $this->tenants->a2);
    ($this->scanAs)($a2Staff, at: $this->tenants->locationOf($this->tenants->a2), business: $this->tenants->a2);
    $scan = ($this->onlyScan)();

    ($this->as)($a2Staff, $this->tenants->a2)->post(route('counter.scans.redeem', [$scan, $reward->id]))
        ->assertInertiaFlash('redeemed.reward', $reward->reward_text);
});

it('gives the customer\'s short code with their QR', function (): void {
    $this->actingAs($this->customer)->get(route('app.qr'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('shortCode', MemberQrToken::shortCode($this->customer)));
});

it('never locks a staff member out over a bad QR the camera keeps reading', function (): void {
    $forged = 'PC1.'.$this->customer->id.'.'.MemberQrToken::windowAt(now()).'.AAAAAAAAAAAAAAAAAAAAAA';

    foreach (range(1, ScanMemberQr::STAFF_WRONG_CODES + 5) as $read) {
        ($this->scanAs)($this->staff, $forged)->assertSessionHasErrors('code');
        $this->travel(4)->seconds();
    }

    ($this->scanAs)($this->staff)->assertRedirect(route('counter.scans.show', ($this->onlyScan)()));
});

it('shows no customer on a scan that ran out unused, and the answer of one that was used', function (): void {
    ($this->scanAs)($this->staff);
    $scan = ($this->onlyScan)();
    $this->travel(CounterScan::SECONDS + 1)->seconds();

    ($this->as)($this->staff)->get(route('counter.scans.show', $scan))
        ->assertRedirect(route('counter.scan'))
        ->assertInertiaFlash('scanRefusal', 'expired');

    $this->travel(5)->minutes();
    ($this->scanAs)($this->staff);
    $used = $this->context->bypass(fn (): CounterScan => CounterScan::query()->latest('scanned_at')->firstOrFail());
    ($this->as)($this->staff)->post(route('counter.scans.stamp', $used), ['qty' => 1, 'key' => 'key-1']);
    $this->travel(CounterScan::SECONDS + 1)->seconds();

    ($this->as)($this->staff)->get(route('counter.scans.show', $used))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('stamped', true));
});

it('keeps a scan from staff no longer working at its site', function (): void {
    ($this->scanAs)($this->staff);
    $scan = ($this->onlyScan)();
    $elsewhere = $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create());
    $this->context->bypass(fn () => $this->tenants->a1->members()->updateExistingPivot($this->staff->id, ['location_id' => $elsewhere->id]));

    ($this->as)($this->staff)->get(route('counter.scans.show', $scan))->assertForbidden();
});

it('redeems only with the staff member\'s own scan, in its business', function (): void {
    $reward = $this->tenants->reward($this->tenants->enroll($this->customer, $this->tenants->cardA));
    ($this->scanAs)($this->staff);
    $scan = ($this->onlyScan)();
    $colleague = $this->tenants->member(User::factory()->create(), $this->tenants->a1);

    ($this->as)($colleague)->post(route('counter.scans.redeem', [$scan, $reward->id]))->assertForbidden();
    ($this->as)($this->tenants->member(User::factory()->create(), $this->tenants->a2), $this->tenants->a2)->post(route('counter.scans.redeem', [$scan, $reward->id]))->assertNotFound();
    ($this->as)($this->tenants->member(User::factory()->create(), $this->tenants->b1), $this->tenants->b1)->post(route('counter.scans.redeem', [$scan, $reward->id]))->assertNotFound();
    ($this->as)($this->staff)->post('/business/counter/scans/'.$scan->id.'/rewards/99999999999999999999/redeem')->assertNotFound();

    expect($this->context->bypass(fn () => $reward->fresh()?->status))->toBe(RewardStatus::Available);
});

it('gives the same answer to a retry, stamping or redeeming once', function (): void {
    $reward = $this->tenants->reward($this->tenants->enroll($this->customer, $this->tenants->cardA));
    ($this->scanAs)($this->staff);
    $scan = ($this->onlyScan)();

    foreach (range(1, 2) as $attempt) {
        ($this->as)($this->staff)->post(route('counter.scans.stamp', $scan), ['qty' => 2, 'key' => 'key-1'])
            ->assertInertiaFlash('stampResult', ['state' => 'done', 'qty' => 2, 'rewards' => 0]);
        ($this->as)($this->staff)->post(route('counter.scans.redeem', [$scan, $reward->id]))
            ->assertInertiaFlash('redeemed.reward', $reward->reward_text);
    }

    expect($this->context->bypass(fn (): int => (int) CardEnrollment::query()->where('user_id', $this->customer->id)->sole()->current_stamps))->toBe(2)
        ->and($this->context->bypass(fn (): int => Reward::query()->where('status', RewardStatus::Redeemed)->count()))->toBe(1);
});

it('says when today\'s limit or a paused card refuses the stamp, and still redeems on a paused card', function (): void {
    $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['daily_cap' => 1])->save());
    ($this->scanAs)($this->staff);
    $scan = ($this->onlyScan)();

    ($this->as)($this->staff)->post(route('counter.scans.stamp', $scan), ['qty' => 2, 'key' => 'key-1'])
        ->assertInertiaFlash('stampResult.state', 'cap');

    $reward = $this->tenants->reward($this->context->bypass(fn (): CardEnrollment => CardEnrollment::query()->firstOrCreate(['card_id' => $this->tenants->cardA->id, 'user_id' => $this->customer->id])));
    $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['active' => false])->save());

    ($this->as)($this->staff)->post(route('counter.scans.stamp', $scan), ['qty' => 1, 'key' => 'key-2'])
        ->assertInertiaFlash('stampResult.state', 'paused');
    ($this->as)($this->staff)->get(route('counter.scans.show', $scan))
        ->assertInertia(fn (Assert $page): Assert => $page->where('card.paused', true)->has('rewards', 1));
    ($this->as)($this->staff)->post(route('counter.scans.redeem', [$scan, $reward->id]))
        ->assertInertiaFlash('redeemed.reward', $reward->reward_text);
});
