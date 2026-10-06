<?php

declare(strict_types=1);

use App\Actions\Taps\DescribeTap;
use App\Enums\BusinessRole;
use App\Enums\RewardStatus;
use App\Enums\TapStatus;
use App\Models\NfcTag;
use App\Models\Reward;
use App\Models\Tap;
use App\Models\User;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The customer's reward screens (CHW-26)
|--------------------------------------------------------------------------
|
| My rewards lists what is still to redeem; "Redeem now" opens the redeem
| window and the redeem screen (C4), which shows the redemption the next tap
| makes. Only the customer's own rewards: anyone else's is a 404.
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

    $this->tapUrl = function (int $counter): string {
        $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($this->stamper->nfc_tag_id));

        return route('taps.receive', app(FakeTap::class)->build($tag->uid, $counter));
    };
    $this->fresh = fn (Reward $reward): Reward => $this->context->bypass(fn (): Reward => Reward::query()->findOrFail($reward->id));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('lists the customer\'s rewards still to redeem, and only theirs', function (): void {
    $other = User::factory()->create();
    $this->tenants->reward($this->tenants->enroll($other, $this->tenants->cardA));

    $this->actingAs($this->customer)->get(route('rewards.index'))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('rewards/index')
            ->has('rewards', 1)
            ->where('rewards.0.id', $this->reward->id)
            ->where('rewards.0.rewardText', 'Free coffee')
            ->where('rewards.0.businessName', $this->tenants->orgA->name)
            ->where('rewards.0.unlockedOn', '2026-10-05'));
});

it('asks a guest to sign in', function (): void {
    $this->get(route('rewards.index'))->assertRedirect(route('login'));
    $this->post(route('rewards.redeem', $this->reward->id))->assertRedirect(route('login'));
    $this->get(route('rewards.redeem.show', $this->reward->id))->assertRedirect(route('login'));
});

it('opens the redeem window on Redeem now, then shows the redeem screen', function (): void {
    $this->actingAs($this->customer)->post(route('rewards.redeem', $this->reward->id))
        ->assertRedirect(route('rewards.redeem.show', $this->reward->id));

    $this->get(route('rewards.redeem.show', $this->reward->id))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('rewards/redeem')
            ->where('reward.status', 'available')
            ->where('reward.verified', true)
            ->where('reward.secondsLeft', 60)
            ->where('reward.redeemed', null));
});

it('keeps another customer\'s reward out of reach, even from the café\'s owner', function (string $who): void {
    $other = User::factory()->create();

    if ($who === 'the owner') {
        $this->tenants->member($other, $this->tenants->a1, BusinessRole::Owner);
    }

    // Their own reward has a window open: a refused POST leaves it alone.
    $own = $this->tenants->reward($this->tenants->enroll($other, $this->tenants->cardA));
    $this->actingAs($other)->post(route('rewards.redeem', $own->id));

    $this->actingAs($other)->post(route('rewards.redeem', $this->reward->id))->assertNotFound();
    $this->actingAs($other)->get(route('rewards.redeem.show', $this->reward->id))->assertNotFound();
    $this->actingAs($other)->get(route('rewards.redeem.show', 999_999))->assertNotFound();

    expect(($this->fresh)($this->reward)->redeem_window_until)->toBeNull()
        ->and(($this->fresh)($own)->redeem_window_until)->not->toBeNull();
})->with(['another customer', 'the owner']);

it('closes the window on Back, so the next tap stamps', function (): void {
    $this->actingAs($this->customer)->post(route('rewards.redeem', $this->reward->id));

    $this->delete(route('rewards.redeem.close', $this->reward->id))->assertRedirect(route('rewards.index'));
    $this->travel(5)->seconds();
    $this->get(($this->tapUrl)(5));

    expect(($this->fresh)($this->reward)->redeem_window_until)->toBeNull()
        ->and(($this->fresh)($this->reward)->status)->toBe(RewardStatus::Available);
    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->component('tap/stamped'));
});

it('closes only the customer\'s own window', function (): void {
    $this->actingAs($this->customer)->post(route('rewards.redeem', $this->reward->id));

    $this->actingAs(User::factory()->create())->delete(route('rewards.redeem.close', $this->reward->id))->assertNotFound();

    expect(($this->fresh)($this->reward)->redeem_window_until)->not->toBeNull();
});

it('rounds the seconds left down, so the ring never outlasts the window', function (): void {
    $this->actingAs($this->customer)->post(route('rewards.redeem', $this->reward->id));
    Carbon::setTestNow('2026-10-05 10:00:00.400');

    $this->get(route('rewards.redeem.show', $this->reward->id))->assertInertia(fn (Assert $page): Assert => $page
        ->where('reward.secondsLeft', 59));
});

it('keeps the redeem screen\'s polling off the other routes\' limits', function (): void {
    $this->actingAs($this->customer);

    foreach (range(1, 35) as $poll) {
        $this->get(route('rewards.redeem.show', $this->reward->id))->assertOk();
    }

    $this->post(route('rewards.redeem', $this->reward->id))->assertRedirect(route('rewards.redeem.show', $this->reward->id));
});

it('never reopens a window on a reward already redeemed', function (): void {
    $this->actingAs($this->customer)->post(route('rewards.redeem', $this->reward->id));
    $this->travel(5)->seconds();
    $this->get(($this->tapUrl)(5));

    $this->post(route('rewards.redeem', $this->reward->id))
        ->assertRedirect(route('rewards.redeem.show', $this->reward->id));

    expect(($this->fresh)($this->reward)->redeem_window_until)->toBeNull()
        ->and(($this->fresh)($this->reward)->redeem_window_opened_at)->toBeNull();
});

it('dates a reward by the day it was unlocked where it was', function (): void {
    // 22:30 UTC is 02:30 the next day in Dubai (a fixed UTC+4).
    Carbon::setTestNow('2026-10-05 22:30:00');
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save());
    $this->context->bypass(fn () => $this->enrollment->forceFill(['current_stamps' => 9, 'lifetime_stamps' => 9])->save());
    $this->actingAs($this->customer)->get(($this->tapUrl)(5));

    $this->get(route('rewards.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->has('rewards', 2)
        ->where('rewards.0.unlockedOn', '2026-10-06'));
});

it('asks an unverified customer to verify, and opens no window', function (): void {
    $this->customer->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($this->customer)->post(route('rewards.redeem', $this->reward->id))
        ->assertRedirect(route('rewards.redeem.show', $this->reward->id));

    $this->get(route('rewards.redeem.show', $this->reward->id))->assertInertia(fn (Assert $page): Assert => $page
        ->where('reward.verified', false)
        ->where('reward.secondsLeft', 0));
    expect(($this->fresh)($this->reward)->redeem_window_until)->toBeNull();
});

it('shows the redemption the next tap makes, live for two minutes from it, on the first look only', function (): void {
    // Asia/Dubai: a fixed UTC+4 in every tz database.
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save());
    $this->actingAs($this->customer)->post(route('rewards.redeem', $this->reward->id));
    $this->travel(5)->seconds();

    $this->get(($this->tapUrl)(5))->assertRedirect(route('taps.result'));

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('tap/redeemed')
        ->where('rewardText', 'Free coffee')
        ->where('card.businessName', 'A1')
        ->where('redeemedAt.time', '14:00')
        ->where('liveSeconds', 120)
        ->where('fresh', true));
    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->where('fresh', false));

    $this->get(route('rewards.redeem.show', $this->reward->id))->assertInertia(fn (Assert $page): Assert => $page
        ->where('reward.status', 'redeemed')
        ->where('reward.secondsLeft', 0)
        ->where('reward.redeemed.locationName', 'A1 site')
        ->where('reward.redeemed.at.time', '14:00')
        ->where('reward.redeemed.liveSeconds', 120));
    $this->get(route('rewards.index'))->assertInertia(fn (Assert $page): Assert => $page->has('rewards', 0));

    $this->travel(119)->seconds();
    $this->get(route('rewards.redeem.show', $this->reward->id))->assertInertia(fn (Assert $page): Assert => $page
        ->where('reward.redeemed.liveSeconds', 1));

    $this->travel(1)->seconds();
    $this->get(route('rewards.redeem.show', $this->reward->id))->assertInertia(fn (Assert $page): Assert => $page
        ->where('reward.redeemed.liveSeconds', 0));
    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page->where('liveSeconds', 0));
    expect(($this->fresh)($this->reward)->status)->toBe(RewardStatus::Redeemed);
});

it('offers the reward a stamp just unlocked, until it is redeemed (C3)', function (): void {
    $this->context->bypass(fn () => $this->enrollment->forceFill(['current_stamps' => 9, 'lifetime_stamps' => 9])->save());

    $this->actingAs($this->customer)->get(($this->tapUrl)(5));
    $unlocked = $this->context->bypass(fn (): Reward => Reward::query()->whereKeyNot($this->reward->id)->firstOrFail());

    $this->get(route('taps.result'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('tap/stamped')
        ->where('rewards', [['id' => $unlocked->id, 'text' => $unlocked->reward_text, 'status' => 'available']]));

    $this->post(route('rewards.redeem', $unlocked->id));
    $this->travel(5)->seconds();
    $this->get(($this->tapUrl)(6));
    $stamped = $this->context->bypass(fn (): Tap => Tap::query()->where('status', TapStatus::Stamped)->sole());

    expect(($this->fresh)($unlocked)->status)->toBe(RewardStatus::Redeemed)
        ->and(app(DescribeTap::class)->handle($stamped)['props']['rewards'])->toBe([['id' => $unlocked->id, 'text' => $unlocked->reward_text, 'status' => 'redeemed']]);
});
