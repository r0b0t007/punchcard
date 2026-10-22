<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Actions\Cards\EnrollCustomer;
use App\Actions\Cards\JoinCard;
use App\Actions\Counter\RedeemScan;
use App\Actions\Counter\ScanMemberQr;
use App\Actions\Counter\StampScan;
use App\Actions\Rewards\RedeemRefused;
use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRejected;
use App\Actions\Stamps\StampRequest;
use App\Actions\Taps\ApplyTap;
use App\Actions\Taps\DescribeTap;
use App\Actions\Taps\ReceiveTap;
use App\Enums\RedeemRefusal;
use App\Enums\RewardStatus;
use App\Enums\StampRejection;
use App\Enums\StampSource;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Http\Middleware\SetTenant;
use App\Models\CardEnrollment;
use App\Models\CounterScan;
use App\Models\NfcTag;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Models\User;
use App\Support\MemberQr\MemberQrToken;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Nothing more on a deleted account's cards (CHW-253)
|--------------------------------------------------------------------------
|
| An account with history is anonymised, not deleted, and keeps its cards
| for the ledger. A request that began before the deletion (a tap made
| signed in, a scan of the member QR) must not stamp, enrol or redeem once
| it commits: AddStamps, EnrollCustomer and RedeemReward read the account
| again after their locks. Each test acts with the models it loaded before
| the deletion, as such a request does. Unused rewards expire with it.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-22 10:00:00');
    config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
    app()->forgetInstance(KeyDiversifier::class);
    app()->setLocale('en');

    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = User::factory()->create();
    $this->staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->here = $this->tenants->locationOf($this->tenants->a1);
    $this->card = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->context->bypass(fn () => $this->card->forceFill(['current_stamps' => 1, 'lifetime_stamps' => 1])->save());
    // History, a visit ago: the account is anonymised, and keeps this card.
    $this->tenants->stamp($this->card, $this->tenants->a1, ['created_at' => now()->subWeek()]);

    $this->delete = function (?User $user = null): void {
        app(DeleteAccount::class)->handle(User::query()->findOrFail(($user ?? $this->customer)->id));
        expect(($user ?? $this->customer)->fresh()?->isAnonymised())->toBeTrue();
    };
    $this->tapAt = function (int $counter, ?User $user = null): Tap {
        $stamper = $this->tenants->stamper($this->tenants->a1);
        $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($stamper->nfc_tag_id));
        $url = app(FakeTap::class)->build($tag->uid, $counter);

        return app(ReceiveTap::class)->handle($url['e'], $url['c'], $user ?? $this->customer, '203.0.113.7', 'Test phone');
    };
    $this->scan = function (): CounterScan {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        return app(ScanMemberQr::class)->handle($this->staff, $this->here, MemberQrToken::issue($this->customer)['token']);
    };
    $this->stamps = fn (): int => $this->context->bypass(fn (): int => StampEvent::query()->count());
    $this->reason = function (Closure $act): mixed {
        try {
            $act();
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

it('refuses a tap made before the deletion, on the card the account keeps', function (): void {
    $tap = ($this->tapAt)(5);
    $before = ($this->stamps)();
    ($this->delete)();

    $applied = app(ApplyTap::class)->handle($tap, $this->customer);

    expect($applied->status)->toBe(TapStatus::Rejected)
        ->and($applied->rejection)->toBe(TapRejection::AccountDeleted)
        ->and(($this->stamps)())->toBe($before)
        ->and($this->context->bypass(fn (): int => (int) $this->card->fresh()?->current_stamps))->toBe(1)
        ->and(app(DescribeTap::class)->handle($applied))->toBe(['component' => 'tap/refused', 'props' => ['reason' => 'deleted']]);
});

it('refuses a tap made before the deletion inside a redeem window, and redeems nothing', function (): void {
    $reward = $this->tenants->reward($this->card);
    $this->context->bypass(fn () => $reward->forceFill(['redeem_window_opened_at' => now()->subMinute(), 'redeem_window_until' => now()->addMinutes(4)])->save());
    $tap = ($this->tapAt)(5);
    ($this->delete)();

    $applied = app(ApplyTap::class)->handle($tap, $this->customer);
    $after = $this->context->bypass(fn (): ?Reward => $reward->fresh());

    expect($applied->rejection)->toBe(TapRejection::AccountDeleted)
        ->and($applied->reward_id)->toBeNull()
        ->and($after?->status)->toBe(RewardStatus::Expired)
        ->and($after?->redeemed_at)->toBeNull()
        ->and($after?->redeem_window_until)->toBeNull();
});

it('rolls back a card made while the account was being deleted', function (): void {
    $other = User::factory()->create();
    // The deletion commits while the insert waits on the user row: here, as the card is written.
    CardEnrollment::created(fn (CardEnrollment $made) => DB::table('users')->where('id', $made->user_id)->update(['anonymised_at' => now()]));

    expect(($this->reason)(fn () => app(EnrollCustomer::class)->handle($this->tenants->a1, $other)))->toBe(StampRejection::AccountDeleted)
        ->and($this->context->bypass(fn (): bool => CardEnrollment::query()->where('user_id', $other->id)->exists()))->toBeFalse();
});

it('enrols a deleted account on no new card', function (): void {
    // Their history is at another organization: they hold no card here.
    $other = User::factory()->create();
    $this->tenants->stamp($this->tenants->enroll($other, $this->tenants->cardB), $this->tenants->b1);
    $tap = ($this->tapAt)(5, $other);
    ($this->delete)($other);
    $cardHere = fn (): bool => $this->context->bypass(fn (): bool => CardEnrollment::query()->where('card_id', $this->tenants->cardA->id)->where('user_id', $other->id)->exists());

    $applied = app(ApplyTap::class)->handle($tap, $other);

    expect($applied->rejection)->toBe(TapRejection::AccountDeleted)
        ->and($cardHere())->toBeFalse()
        ->and(app(JoinCard::class)->handle($this->tenants->a1, $other))->toBeNull()
        ->and($cardHere())->toBeFalse();
});

it('refuses staff and system stamps, still takes a correction, and replays a stamp given before', function (): void {
    $add = fn (StampRequest $request): mixed => $this->context->bypass(function () use ($request): mixed {
        try {
            return app(AddStamps::class)->handle($this->card, $request);
        } catch (StampRejected $rejected) {
            return $rejected->rejection;
        }
    });
    $given = $add(StampRequest::manual($this->here, $this->staff, 'key-1', 'Forgot the card', 1));
    ($this->delete)();

    expect($add(StampRequest::manual($this->here, $this->staff, 'key-2', 'Forgot the card', 1)))->toBe(StampRejection::AccountDeleted)
        ->and($add(StampRequest::qr($this->here, $this->staff, 'key-3')))->toBe(StampRejection::AccountDeleted)
        ->and($add(StampRequest::system(StampSource::Bonus, $this->here, 'key-4')))->toBe(StampRejection::AccountDeleted)
        ->and($add(StampRequest::manual($this->here, $this->staff, 'key-1', 'Forgot the card', 1))->event->id)->toBe($given->event->id)
        ->and($add(StampRequest::correction($this->here, $this->staff, 'key-5', 'Given twice', -1))->event->qty)->toBe(-1);
});

it('refuses a staff scan made before the deletion, and leaves it unused', function (): void {
    $scan = ($this->scan)();
    ($this->delete)();

    expect(($this->reason)(fn () => app(StampScan::class)->handle($scan, $this->staff, 1, 'key-1')))->toBe(StampRejection::AccountDeleted)
        ->and($scan->fresh()?->stamp_event_id)->toBeNull();
});

it('gives a retried scan the stamp it gave before the deletion', function (): void {
    $scan = ($this->scan)();
    $given = app(StampScan::class)->handle($scan, $this->staff, 1, 'key-1');
    ($this->delete)();

    $again = app(StampScan::class)->handle($scan, $this->staff, 1, 'key-1');

    expect($again->replayed)->toBeTrue()->and($again->event->id)->toBe($given->event->id);
});

it('says the account was deleted before anything else when redeeming', function (): void {
    $scan = ($this->scan)();
    ($this->delete)();
    // Still available, as a reward unlocked as the account went would be until the deletion expires it.
    $reward = $this->tenants->reward($this->card);

    expect(($this->reason)(fn () => app(RedeemScan::class)->handle($scan, $this->staff, $reward)))->toBe(RedeemRefusal::AccountDeleted)
        ->and($this->context->bypass(fn (): ?RewardStatus => $reward->fresh()?->status))->toBe(RewardStatus::Available);
});

it('expires the rewards not yet redeemed, and redeems none of them', function (): void {
    $waiting = $this->tenants->reward($this->card);
    $redeemed = $this->context->bypass(fn (): Reward => Reward::factory()->for($this->card, 'enrollment')->create([
        'milestone' => 2, 'status' => RewardStatus::Redeemed, 'redeemed_at' => now()->subDay(), 'redeemed_business_id' => $this->tenants->a1->id,
    ]));
    $scan = ($this->scan)();
    ($this->delete)();

    expect($this->context->bypass(fn (): array => [$waiting->fresh()?->status, $waiting->fresh()?->expires_at?->equalTo(now()), $redeemed->fresh()?->status]))
        ->toBe([RewardStatus::Expired, true, RewardStatus::Redeemed])
        ->and(($this->reason)(fn () => app(RedeemScan::class)->handle($scan, $this->staff, $waiting)))->toBe(RedeemRefusal::AccountDeleted);
});

it('tells staff on the scanner that the account was deleted', function (): void {
    $reward = $this->tenants->reward($this->card);
    $scan = ($this->scan)();
    ($this->delete)();
    $as = fn () => $this->actingAs($this->staff)->withSession([SetTenant::SESSION_KEY => 'business:'.$this->tenants->a1->id]);

    $as()->post(route('counter.scans.stamp', $scan), ['qty' => 1, 'key' => 'key-1'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This customer\'s account was deleted: nothing was added.']);
    $as()->post(route('counter.scans.redeem', [$scan, $reward->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This customer\'s account was deleted: nothing was redeemed.']);
});
