<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Actions\Cards\ReferrerFor;
use App\Actions\Referrals\GiveReferralStamp;
use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRequest;
use App\Actions\Taps\ApplyTap;
use App\Actions\Taps\ReceiveTap;
use App\Enums\StampSource;
use App\Enums\TapStatus;
use App\Http\Requests\Counter\StampScanRequest;
use App\Listeners\GiveReferralStamps;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\NfcTag;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Nfc\FakeTap;
use App\Support\Referrals\PendingReferral;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Refer a friend (CHW-47)
|--------------------------------------------------------------------------
|
| A customer's link brings a friend to a business's join page; the card the
| friend adds there, or by a first tap, is referred by them when the card's
| referrals are on and the friend is new to the organization, never by
| their own code. Once the friend proves they came in, the referrer gets +1
| (a referral stamp), once per friend, within the card's monthly cap.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-07 12:00:00');
    config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['referrals_enabled' => true])->save());
    $this->referrer = User::factory()->create();
    $this->invite = $this->tenants->enroll($this->referrer, $this->tenants->cardA);
    $this->context->bypass(fn () => $this->invite->forceFill(['referral_code' => 'FRENDS23'])->save());
    $this->friend = User::factory()->create();

    // The friend opens the referrer's link to A1's join page, then adds the card there.
    $this->joins = function (string $code = 'FRENDS23', ?string $linkTo = null, ?User $as = null): ?CardEnrollment {
        $as ??= $this->friend;
        $this->get(route('join.show', $linkTo ?? $this->tenants->a1->slug).'?ref='.$code)->assertOk();
        $this->actingAs($as)->post(route('join.store', $this->tenants->a1->slug))->assertRedirect();

        return $this->context->bypass(fn (): ?CardEnrollment => CardEnrollment::query()->where('user_id', $as->id)->where('card_id', $this->tenants->cardA->id)->first());
    };
    $this->referred = fn (?CardEnrollment $enrollment): ?int => $enrollment?->referred_by;
    // The friend's card, referred by the referrer, as joining would make it.
    $this->friendCard = fn (): CardEnrollment => $this->context->bypass(fn (): CardEnrollment => CardEnrollment::factory()->for($this->tenants->cardA, 'card')->for($this->friend)
        ->create(['referred_by' => $this->invite->id]));
    $this->referralStamps = fn (): array => $this->context->bypass(fn (): array => StampEvent::query()->where('source', StampSource::Referral)->orderBy('id')
        ->get()->map(fn (StampEvent $stamp): array => [$stamp->enrollment_id, $stamp->business_id, $stamp->qty, $stamp->idempotency_key])->all());
    $this->give = fn (CardEnrollment $friend, ?int $businessId = null): bool => app(GiveReferralStamp::class)->handle($friend->id, $businessId ?? $this->tenants->a1->id);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('refers the card a friend adds on the join page their link opened', function (): void {
    expect(($this->referred)(($this->joins)()))->toBe($this->invite->id);
});

it('keeps the invitation through signing in, for the business its link named only', function (): void {
    // Opened signed out, then the friend signs in and comes back to add the card.
    $this->get(route('join.show', $this->tenants->a1->slug).'?ref=frends23')->assertOk();
    $this->actingAs($this->friend)->post(route('join.store', $this->tenants->a1->slug));

    expect(($this->referred)($this->context->bypass(fn (): ?CardEnrollment => CardEnrollment::query()->where('user_id', $this->friend->id)->first())))->toBe($this->invite->id)
        // A link to A2, though the card is the same, refers nothing added at A1.
        ->and(($this->referred)(($this->joins)(linkTo: $this->tenants->a2->slug, as: User::factory()->create())))->toBeNull();
});

it('refers nobody with a code that is no code of this card, nor while the card\'s referrals are off', function (string $case): void {
    $code = match ($case) {
        'unknown' => 'NXSUCH23',
        'malformed' => 'friend 23 <b>',
        'another organization\'s' => (function (): string {
            $elsewhere = $this->tenants->enroll(User::factory()->create(), $this->tenants->cardB);
            $this->context->bypass(fn () => $elsewhere->forceFill(['referral_code' => 'XTHERB23'])->save());

            return 'XTHERB23';
        })(),
        'referrals off' => (function (): string {
            $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['referrals_enabled' => false])->save());

            return 'FRENDS23';
        })(),
    };

    $enrollment = ($this->joins)($code);

    expect($enrollment)->not->toBeNull()->and(($this->referred)($enrollment))->toBeNull();
})->with(['unknown', 'malformed', 'another organization\'s', 'referrals off']);

it('counts only someone new to the organization, never the referrer themself', function (): void {
    $card = $this->context->bypass(fn (): LoyaltyCard => $this->tenants->cardA->refresh());

    $alreadyHere = User::factory()->create();
    $otherCard = $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::factory()->for($this->tenants->orgA)->create());
    $this->tenants->enroll($alreadyHere, $otherCard);

    expect($this->context->bypass(fn (): ?CardEnrollment => app(ReferrerFor::class)->handle($card->id, $this->friend, 'FRENDS23'))?->id)->toBe($this->invite->id)
        ->and($this->context->bypass(fn (): ?CardEnrollment => app(ReferrerFor::class)->handle($card->id, $alreadyHere, 'FRENDS23')))->toBeNull()
        ->and($this->context->bypass(fn (): ?CardEnrollment => app(ReferrerFor::class)->handle($card->id, $this->referrer, 'FRENDS23')))->toBeNull()
        ->and($this->context->bypass(fn (): ?CardEnrollment => app(ReferrerFor::class)->handle($card->id, $this->friend, null)))->toBeNull();
});

it('never changes who referred a card held already', function (): void {
    $held = $this->tenants->enroll($this->friend, $this->tenants->cardA);

    expect(($this->referred)(($this->joins)()))->toBeNull()
        ->and(($this->joins)()?->id)->toBe($held->id);
});

it('refers the card a first tap adds, the link opened before', function (): void {
    $session = app('session.store');
    PendingReferral::remember($session, $this->tenants->a1, 'FRENDS23');
    $stamper = $this->tenants->stamper($this->tenants->a1);
    $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($stamper->nfc_tag_id));
    $url = app(FakeTap::class)->build($tag->uid, 7);
    $tap = app(ReceiveTap::class)->handle($url['e'], $url['c'], $this->friend, '203.0.113.7', 'Test phone');

    $applied = app(ApplyTap::class)->handle($tap, $this->friend, PendingReferral::from($session));

    expect($applied->status)->toBe(TapStatus::Stamped)
        ->and(($this->referred)($this->context->bypass(fn (): ?CardEnrollment => CardEnrollment::query()->where('user_id', $this->friend->id)->first())))->toBe($this->invite->id);
});

it('gives the referrer +1 once the friend proves they came in, once whatever comes after', function (): void {
    $friend = ($this->friendCard)();
    $this->tenants->stamp($friend, $this->tenants->a1);

    expect(($this->give)($friend))->toBeTrue()
        ->and(($this->give)($friend))->toBeFalse()
        // Their visit to A2 the same week: the friend was counted already.
        ->and(($this->give)($friend, $this->tenants->a2->id))->toBeFalse()
        ->and(($this->referralStamps)())->toBe([[$this->invite->id, $this->tenants->a1->id, 1, 'referral:'.$friend->id]]);
});

it('gives nothing before the friend proves they came in: a bonus or a birthday is no visit', function (StampSource $source): void {
    $friend = ($this->friendCard)();
    $this->tenants->stamp($friend, $this->tenants->a1, ['source' => $source, 'idempotency_key' => 'system-1', 'staff_id' => null]);

    expect(($this->give)($friend))->toBeFalse()->and(($this->referralStamps)())->toBe([]);
})->with([StampSource::Bonus, StampSource::Birthday]);

it('gives nothing for a card nobody referred, nor once the card\'s referrals are off', function (): void {
    $unreferred = $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA);
    $this->tenants->stamp($unreferred, $this->tenants->a1);
    $friend = ($this->friendCard)();
    $this->tenants->stamp($friend, $this->tenants->a1);
    $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['referrals_enabled' => false])->save());

    expect(($this->give)($unreferred))->toBeFalse()
        ->and(($this->give)($friend))->toBeFalse()
        ->and(($this->referralStamps)())->toBe([]);
});

it('keeps the referrer within the card\'s monthly cap, in the business\'s calendar month', function (): void {
    $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['referral_monthly_cap' => 2])->save());
    $friends = collect(range(1, 3))->map(function (): CardEnrollment {
        $friend = $this->context->bypass(fn (): CardEnrollment => CardEnrollment::factory()->for($this->tenants->cardA, 'card')->for(User::factory()->create())
            ->create(['referred_by' => $this->invite->id]));
        $this->tenants->stamp($friend, $this->tenants->a1);

        return $friend;
    });

    expect($friends->map(fn (CardEnrollment $friend): bool => ($this->give)($friend))->all())->toBe([true, true, false]);

    // 00:30 on 1 December in Casablanca, still November in UTC: a new month, room again.
    Carbon::setTestNow(CarbonImmutable::parse('2026-12-01 00:30:00', 'Africa/Casablanca')->utc());
    $this->tenants->stamp($friends[2], $this->tenants->a1);

    expect(($this->give)($friends[2]))->toBeTrue()
        ->and(count(($this->referralStamps)()))->toBe(3);
});

it('unlocks the referrer\'s reward when the referral stamp completes their card', function (): void {
    $this->context->bypass(fn () => $this->invite->forceFill(['current_stamps' => $this->tenants->cardA->stamps_required - 1])->save());
    $friend = ($this->friendCard)();
    $this->tenants->stamp($friend, $this->tenants->a1);

    ($this->give)($friend);

    expect($this->context->bypass(fn (): int => Reward::query()->where('enrollment_id', $this->invite->id)->count()))->toBe(1);
});

it('gives the referrer their stamp after the friend\'s first visit, through the friend\'s own stamp', function (): void {
    $friend = ($this->friendCard)();
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);

    $this->context->bypass(fn () => app(AddStamps::class)->handle($friend, StampRequest::qr($this->tenants->locationOf($this->tenants->a1), $staff, 'scan-1')));

    expect(($this->referralStamps)())->toBe([[$this->invite->id, $this->tenants->a1->id, 1, 'referral:'.$friend->id]]);
});

it('queues nothing for a stamp that is no referred friend\'s visit', function (): void {
    Queue::fake();
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $here = $this->tenants->locationOf($this->tenants->a1);
    $friend = ($this->friendCard)();
    $unreferred = $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA);

    $this->context->bypass(fn () => app(AddStamps::class)->handle($unreferred, StampRequest::qr($here, $staff, 'scan-1')));
    $this->context->bypass(fn () => app(AddStamps::class)->handle($friend, StampRequest::system(StampSource::Bonus, $here, 'bonus-1')));
    Queue::assertNotPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === GiveReferralStamps::class);

    $this->context->bypass(fn () => app(AddStamps::class)->handle($friend, StampRequest::qr($here, $staff, 'scan-2')));
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === GiveReferralStamps::class);
});

it('refers the card a signed-in friend\'s tap adds, through the tap itself', function (): void {
    $stamper = $this->tenants->stamper($this->tenants->a1);
    $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($stamper->nfc_tag_id));
    $url = app(FakeTap::class)->build($tag->uid, 9);
    $this->actingAs($this->friend)->get(route('join.show', $this->tenants->a1->slug).'?ref=FRENDS23')->assertOk();

    $this->actingAs($this->friend)->get(route('taps.receive', ['e' => $url['e'], 'c' => $url['c']]))->assertRedirect();

    expect(($this->referred)($this->context->bypass(fn (): ?CardEnrollment => CardEnrollment::query()->where('user_id', $this->friend->id)->first())))->toBe($this->invite->id);
});

it('waits for the friend\'s verified email, then pays at their next visit', function (): void {
    $this->friend->forceFill(['email_verified_at' => null])->save();
    $friend = ($this->friendCard)();
    $this->tenants->stamp($friend, $this->tenants->a1);

    expect(($this->give)($friend))->toBeFalse();

    $this->friend->forceFill(['email_verified_at' => now()])->save();
    $this->tenants->stamp($friend, $this->tenants->a1, ['created_at' => now()->addDay()]);

    expect(($this->give)($friend))->toBeTrue()->and(($this->referralStamps)())->toHaveCount(1);
});

it('holds a friend\'s referral once in the database too, whatever the business', function (): void {
    $friend = ($this->friendCard)();
    $key = GiveReferralStamp::key($friend->id);
    $this->tenants->stamp($this->invite, $this->tenants->a1, ['source' => StampSource::Referral, 'idempotency_key' => $key, 'staff_id' => null]);

    expect(fn () => DB::transaction(fn () => $this->tenants->stamp($this->invite, $this->tenants->a2, ['source' => StampSource::Referral, 'idempotency_key' => $key, 'staff_id' => null])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('counts the referrer\'s month across the card\'s businesses', function (): void {
    $this->context->bypass(fn () => $this->tenants->cardA->forceFill(['referral_monthly_cap' => 1])->save());
    $first = ($this->friendCard)();
    $this->tenants->stamp($first, $this->tenants->a2);
    expect(($this->give)($first, $this->tenants->a2->id))->toBeTrue();

    $second = $this->context->bypass(fn (): CardEnrollment => CardEnrollment::factory()->for($this->tenants->cardA, 'card')->for(User::factory()->create())
        ->create(['referred_by' => $this->invite->id]));
    $this->tenants->stamp($second, $this->tenants->a1);

    expect(($this->give)($second))->toBeFalse();
});

it('pays nothing, quietly, once the referrer\'s account is gone, and never takes a gone account\'s code', function (): void {
    $friend = ($this->friendCard)();
    $this->tenants->stamp($friend, $this->tenants->a1);
    $this->tenants->stamp($this->invite, $this->tenants->a1, ['created_at' => now()->subWeek()]);
    $card = $this->context->bypass(fn (): LoyaltyCard => $this->tenants->cardA->refresh());
    $another = User::factory()->create();
    app(DeleteAccount::class)->handle($this->referrer);
    Log::spy();

    expect(($this->give)($friend))->toBeFalse()
        ->and($this->context->bypass(fn (): ?CardEnrollment => app(ReferrerFor::class)->handle($card->id, $another, 'FRENDS23')))->toBeNull();
    Log::shouldNotHaveReceived('warning');
});

it('takes only a UUID as a staff scan\'s key, never one only the system writes', function (string $key, bool $refused): void {
    expect(Validator::make(['qty' => 1, 'key' => $key], (new StampScanRequest)->rules())->fails())->toBe($refused);
})->with([
    'a referral\'s' => ['referral:12', true],
    'a birthday\'s' => ['birthday:3:2026', true],
    'anything else' => ['key-1', true],
    'the counter\'s own' => ['0d3c5b8e-6f0a-4d7e-9a3b-2b1f8c9d0e11', false],
]);

it('never counts an alias of the referrer\'s own address as a friend', function (string $referrer, string $friend, bool $counts): void {
    $this->referrer->forceFill(['email' => $referrer])->save();
    $this->friend->forceFill(['email' => $friend])->save();

    expect(($this->referred)(($this->joins)()) !== null)->toBe($counts);
})->with([
    'a +tag' => ['salma@gmail.com', 'salma+1@gmail.com', false],
    'Gmail dots and case' => ['salma.tazi@gmail.com', 'SalmaTazi@googlemail.com', false],
    'a +tag elsewhere' => ['salma@example.ma', 'salma+cafe@example.ma', false],
    'dots elsewhere are another inbox' => ['salma.tazi@example.ma', 'salmatazi@example.ma', true],
    'someone else' => ['salma@gmail.com', 'yasmine@gmail.com', true],
]);

it('credits the referrer where they come, never a sister site they never visited', function (): void {
    $this->tenants->stamp($this->invite, $this->tenants->a1, ['created_at' => now()->subWeek()]);
    $friend = ($this->friendCard)();
    $this->tenants->stamp($friend, $this->tenants->a2);

    expect(($this->give)($friend, $this->tenants->a2->id))->toBeTrue()
        ->and(($this->referralStamps)())->toBe([[$this->invite->id, $this->tenants->a1->id, 1, 'referral:'.$friend->id]]);
});

it('forgets the invitation once the card is added at its business', function (): void {
    ($this->joins)();

    expect(PendingReferral::from(app('session.store')))->toBeNull();
});
