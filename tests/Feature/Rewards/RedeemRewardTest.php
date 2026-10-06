<?php

declare(strict_types=1);

use App\Actions\Rewards\OpenRedeemWindow;
use App\Actions\Rewards\RedeemPresence;
use App\Actions\Rewards\RedeemRefused;
use App\Actions\Rewards\RedeemReward;
use App\Actions\Taps\ApplyTap;
use App\Actions\Taps\DescribeTap;
use App\Actions\Taps\ReceiveTap;
use App\Actions\Tenancy\ArchiveLocation;
use App\Enums\RedeemRefusal;
use App\Enums\RewardStatus;
use App\Enums\StamperStatus;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Events\EnrollmentChanged;
use App\Models\NfcTag;
use App\Models\Reward;
use App\Models\Stamper;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Models\User;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Redeeming a reward (CHW-26)
|--------------------------------------------------------------------------
|
| The customer opens a 60 s redeem window on a reward (Redeem now, C3/C4),
| then taps a stamper of a business that honours the card: that verified tap
| is the presence proof, and it redeems the reward instead of stamping. A
| verified email is required. A reward is redeemed once: asking again gets the
| first redemption back, and a second tap hands over nothing.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 10:00:00');
    config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
    app()->forgetInstance(KeyDiversifier::class);

    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->stamper = $this->tenants->stamper($this->tenants->a1);
    $this->customer = User::factory()->create();
    $this->enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->reward = $this->tenants->reward($this->enrollment);

    // A verified tap, received signed out: pending until someone applies it.
    $this->received = function (int $counter, ?Stamper $stamper = null): Tap {
        $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail(($stamper ?? $this->stamper)->nfc_tag_id));
        $url = app(FakeTap::class)->build($tag->uid, $counter);

        return app(ReceiveTap::class)->handle($url['e'], $url['c'], null, '203.0.113.7', 'Test phone');
    };
    $this->tap = fn (int $counter, ?Stamper $stamper = null, ?User $user = null): Tap => app(ApplyTap::class)->handle(($this->received)($counter, $stamper), $user ?? $this->customer);
    $this->presence = fn (int $counter, ?Stamper $stamper = null): RedeemPresence => RedeemPresence::tap(($this->received)($counter, $stamper));
    $this->fresh = fn (Reward $reward): Reward => $this->context->bypass(fn (): Reward => Reward::query()->findOrFail($reward->id));
    $this->stamps = fn (): int => $this->context->bypass(fn (): int => StampEvent::query()->count());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('redeems a reward with the next tap inside its window, instead of stamping', function (): void {
    Event::fake([EnrollmentChanged::class]);
    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);
    $this->travel(20)->seconds();

    $tap = ($this->tap)(5);
    $reward = ($this->fresh)($this->reward);

    expect($tap->status)->toBe(TapStatus::Redeemed)
        ->and($tap->reward_id)->toBe($this->reward->id)
        ->and($reward->status)->toBe(RewardStatus::Redeemed)
        ->and($reward->redeemed_by)->toBeNull()
        ->and($reward->redeemed_business_id)->toBe($this->tenants->a1->id)
        ->and($reward->redeemed_location_id)->toBe($this->stamper->location_id)
        ->and($reward->redeemed_at?->toDateTimeString())->toBe('2026-10-05 10:00:20')
        ->and($reward->redeem_window_until)->toBeNull()
        ->and(($this->stamps)())->toBe(0);
    Event::assertDispatched(EnrollmentChanged::class, fn (EnrollmentChanged $changed): bool => $changed->rewardIds === [] && $changed->redeemedRewardIds === [$this->reward->id]);
});

it('redeems with a tap at the window\'s last second, and with a tap inside it claimed after it closed', function (): void {
    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);
    $this->travel(10)->seconds();
    $pending = ($this->received)(5);
    $this->travel(5)->minutes();

    $claimed = app(ApplyTap::class)->handle($pending, $this->customer);

    expect($claimed->status)->toBe(TapStatus::Redeemed)
        ->and(($this->fresh)($this->reward)->redeemed_at?->toDateTimeString())->toBe('2026-10-05 10:00:10');

    $second = $this->context->bypass(fn (): Reward => Reward::factory()->for($this->enrollment, 'enrollment')->create(['milestone' => 2]));
    app(OpenRedeemWindow::class)->handle($second, $this->customer);
    $this->travel(60)->seconds();

    expect(($this->tap)(6)->status)->toBe(TapStatus::Redeemed);
});

it('redeems a reward once: asking again returns the first redemption', function (): void {
    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);

    $first = app(RedeemReward::class)->handle($this->reward, $this->customer, ($this->presence)(5));
    $this->travel(5)->minutes();
    $again = app(RedeemReward::class)->handle($this->reward, $this->customer, ($this->presence)(6, $this->tenants->stamper($this->tenants->a2)));

    expect($first->redeemedNow)->toBeTrue()
        ->and($again->redeemedNow)->toBeFalse()
        ->and($again->reward->redeemed_at?->toDateTimeString())->toBe('2026-10-05 10:00:00')
        ->and($again->reward->redeemed_business_id)->toBe($this->tenants->a1->id)
        ->and($this->context->bypass(fn (): int => Reward::query()->where('status', RewardStatus::Redeemed)->count()))->toBe(1);
});

it('hands over nothing for a tap whose reward another tap redeemed meanwhile', function (): void {
    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);
    $atA1 = ($this->received)(5);
    $atA2 = ($this->presence)(5, $this->tenants->stamper($this->tenants->a2));

    // Two taps on two stampers at once: the other one redeems the reward right after this one found it.
    $raced = new stdClass;
    $raced->done = false;
    Reward::retrieved(function () use ($raced, $atA2): void {
        if (! $raced->done) {
            $raced->done = true;
            app(RedeemReward::class)->handle($this->reward, $this->customer, $atA2);
        }
    });

    $tap = app(ApplyTap::class)->handle($atA1, $this->customer);

    expect($raced->done)->toBeTrue()
        ->and($tap->status)->toBe(TapStatus::Rejected)
        ->and($tap->rejection)->toBe(TapRejection::RedeemRefused)
        ->and(($this->fresh)($this->reward)->redeemed_business_id)->toBe($this->tenants->a2->id)
        ->and(($this->stamps)())->toBe(0);
});

it('stamps as usual once the window has closed', function (): void {
    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);
    $this->travel(61)->seconds();

    $tap = ($this->tap)(5);

    expect($tap->status)->toBe(TapStatus::Stamped)
        ->and(($this->fresh)($this->reward)->status)->toBe(RewardStatus::Available)
        ->and(($this->stamps)())->toBe(1);
});

it('never redeems with a tap made before the window opened', function (): void {
    $pending = ($this->received)(5);
    $this->travel(2)->minutes();
    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);

    $applied = app(ApplyTap::class)->handle($pending, $this->customer);

    expect($applied->status)->toBe(TapStatus::Stamped)
        ->and(($this->fresh)($this->reward)->status)->toBe(RewardStatus::Available);
});

it('never redeems on a stamper paused since the tap', function (): void {
    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);
    $pending = ($this->received)(5);
    $this->context->bypass(fn () => $this->stamper->forceFill(['status' => StamperStatus::Disabled])->save());

    $applied = app(ApplyTap::class)->handle($pending, $this->customer);

    expect($applied->status)->toBe(TapStatus::Rejected)
        ->and($applied->rejection)->toBe(TapRejection::StamperDisabled)
        ->and(($this->fresh)($this->reward)->status)->toBe(RewardStatus::Available);
});

it('gives an armed tap its paid stamps first, and redeems with the next tap', function (): void {
    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);
    $this->context->bypass(fn () => $this->stamper->forceFill(['armed_qty' => 3, 'armed_until' => now()->addMinute()])->save());

    $armed = ($this->tap)(5);

    expect($armed->status)->toBe(TapStatus::Stamped)
        ->and($armed->qty)->toBe(3)
        ->and(($this->fresh)($this->reward)->status)->toBe(RewardStatus::Available);

    $this->travel(10)->seconds();

    expect(($this->tap)(6)->status)->toBe(TapStatus::Redeemed);
});

it('never lets another customer\'s open window touch a tap', function (): void {
    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);
    $other = User::factory()->create();

    $tap = ($this->tap)(5, null, $other);

    expect($tap->status)->toBe(TapStatus::Stamped)
        ->and(($this->fresh)($this->reward)->status)->toBe(RewardStatus::Available);
});

it('redeems a franchise reward at any business that honours the card, and nowhere else', function (): void {
    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);

    $atB1 = ($this->tap)(5, $this->tenants->stamper($this->tenants->b1));
    expect($atB1->status)->toBe(TapStatus::Stamped)
        ->and(($this->fresh)($this->reward)->status)->toBe(RewardStatus::Available);

    $a2 = $this->tenants->stamper($this->tenants->a2);
    $atA2 = ($this->tap)(5, $a2);
    $reward = ($this->fresh)($this->reward);

    expect($atA2->status)->toBe(TapStatus::Redeemed)
        ->and($reward->redeemed_business_id)->toBe($this->tenants->a2->id)
        ->and($reward->redeemed_location_id)->toBe($a2->location_id);
});

it('opens a window only on the customer\'s own available reward, with a verified email', function (string $case, RedeemRefusal $refusal): void {
    $user = $case === 'another customer' ? User::factory()->create() : $this->customer;

    if ($case === 'unverified') {
        $this->customer->forceFill(['email_verified_at' => null])->save();
    }

    if ($case === 'redeemed') {
        app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);
        app(RedeemReward::class)->handle($this->reward, $this->customer, ($this->presence)(5));
    }

    expect(fn () => app(OpenRedeemWindow::class)->handle(($this->fresh)($this->reward), $user))
        ->toThrow(function (RedeemRefused $refused) use ($refusal): void {
            expect($refused->refusal)->toBe($refusal);
        });
})->with([
    'another customer\'s reward' => ['another customer', RedeemRefusal::NotYours],
    'an unverified email' => ['unverified', RedeemRefusal::Unverified],
    'a redeemed reward' => ['redeemed', RedeemRefusal::Unavailable],
]);

it('redeems only the customer\'s own reward, verified, inside the window, where the card is honoured', function (string $case, RedeemRefusal $refusal): void {
    $presence = $case === 'before the window' ? ($this->presence)(5) : null;
    $this->travel(1)->seconds();
    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);
    $user = $case === 'another customer' ? User::factory()->create() : $this->customer;

    if ($case === 'too late') {
        $this->travel(61)->seconds();
    }

    if ($case === 'unverified') {
        $this->customer->forceFill(['email_verified_at' => null])->save();
    }

    $presence ??= ($this->presence)(5, $case === 'not honoured' ? $this->tenants->stamper($this->tenants->b1) : null);

    expect(fn () => app(RedeemReward::class)->handle($this->reward, $user, $presence))
        ->toThrow(function (RedeemRefused $refused) use ($refusal): void {
            expect($refused->refusal)->toBe($refusal);
        });
    expect(($this->fresh)($this->reward)->status)->toBe(RewardStatus::Available);
})->with([
    'another customer' => ['another customer', RedeemRefusal::NotYours],
    'an unverified email' => ['unverified', RedeemRefusal::Unverified],
    'after the window closed' => ['too late', RedeemRefusal::OutsideWindow],
    'before the window opened' => ['before the window', RedeemRefusal::OutsideWindow],
    'at a business that does not honour the card' => ['not honoured', RedeemRefusal::NotHonoured],
]);

it('takes presence only from a verified tap still waiting for its outcome', function (): void {
    ($this->received)(5);
    $replayed = ($this->received)(5);

    expect($replayed->rejection)->toBe(TapRejection::Replay);

    RedeemPresence::tap($replayed);
})->throws(LogicException::class, 'Only a verified tap');

it('refuses the redeem tap of a customer whose email is no longer verified, and keeps the reward', function (): void {
    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);
    $this->customer->forceFill(['email_verified_at' => null])->save();

    $tap = ($this->tap)(5);

    expect($tap->status)->toBe(TapStatus::Rejected)
        ->and($tap->rejection)->toBe(TapRejection::RedeemRefused)
        ->and(app(DescribeTap::class)->handle($tap))->toBe(['component' => 'tap/refused', 'props' => ['reason' => 'redeem']])
        ->and(($this->fresh)($this->reward)->status)->toBe(RewardStatus::Available)
        ->and(($this->stamps)())->toBe(0);
});

it('keeps one redeem window open per customer', function (): void {
    $second = $this->context->bypass(fn (): Reward => Reward::factory()->for($this->enrollment, 'enrollment')->create(['milestone' => 2]));

    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);
    app(OpenRedeemWindow::class)->handle($second, $this->customer);

    expect(($this->fresh)($this->reward)->redeem_window_until)->toBeNull()
        ->and(($this->fresh)($second)->redeem_window_until?->toDateTimeString())->toBe('2026-10-05 10:01:00');
});

it('never redeems at a closed site', function (): void {
    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);
    $presence = ($this->presence)(5);
    $this->context->bypass(fn () => app(ArchiveLocation::class)->handle($this->tenants->locationOf($this->tenants->a1)));

    expect(fn () => app(RedeemReward::class)->handle($this->reward, $this->customer, $presence))
        ->toThrow(function (RedeemRefused $refused): void {
            expect($refused->refusal)->toBe(RedeemRefusal::SiteClosed);
        });
    expect(($this->fresh)($this->reward)->status)->toBe(RewardStatus::Available);
});

it('opens and closes redeem windows only through the redeem actions, in bypass()', function (): void {
    $this->context->set($this->tenants->orgA, orgAdmin: true);

    $this->reward->forceFill(['redeem_window_until' => now()->addHour()])->save();
})->throws(LogicException::class, 'redeem Action');

it('describes a redeemed tap for its result page', function (): void {
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save());
    app(OpenRedeemWindow::class)->handle($this->reward, $this->customer);
    $tap = ($this->tap)(5);

    $screen = app(DescribeTap::class)->handle($tap);

    expect($screen['component'])->toBe('tap/redeemed')
        ->and($screen['props']['rewardText'])->toBe('Free coffee')
        ->and($screen['props']['card']['businessName'])->toBe('A1')
        ->and($screen['props']['card']['locationName'])->toBe('A1 site')
        ->and($screen['props']['card']['cardName'])->toBe('A card')
        ->and($screen['props']['redeemedAt'])->toBe(['day' => 'today', 'date' => '2026-10-05', 'time' => '14:00']);
});
