<?php

declare(strict_types=1);

use App\Actions\Counter\RedeemScan;
use App\Actions\Counter\ScanMemberQr;
use App\Actions\Rewards\PushScanRedemption;
use App\Enums\RewardStatus;
use App\Events\EnrollmentChanged;
use App\Listeners\SendRedeemNotices;
use App\Models\Business;
use App\Models\CounterScan;
use App\Models\Reward;
use App\Models\User;
use App\Notifications\RewardRedeemed;
use App\Support\MemberQr\MemberQrToken;
use App\Support\Tenancy\TenantContext;
use GuzzleHttp\Psr7\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Telling the customer staff redeemed their reward with a scan (CHW-228)
|--------------------------------------------------------------------------
|
| A scan of the customer's QR lets staff redeem their rewards without the
| customer opening a redeem window. As a safeguard, the customer is told at
| once: a push on their phones (14a), or else a notice on My cards until
| Got it (14b). Never for their own tap, never twice for one redemption.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-01 10:42:00');
    config(['webpush.vapid.public_key' => 'BAppPublicKey', 'webpush.vapid.private_key' => 'app-private-key']);
    Notification::fake();
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = User::factory()->create();
    $this->staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $this->enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->reward = $this->tenants->reward($this->enrollment);
    $this->phone = fn (User $user): PushSubscription => $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/'.$user->id, 'BKey', 'Secret');

    // Staff at A1 scan the customer's QR (once per test: a member QR counts once) and redeem, as the counter does.
    $this->scan = function (): CounterScan {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);
        $scan = app(ScanMemberQr::class)->handle($this->staff, $this->tenants->locationOf($this->tenants->a1), MemberQrToken::issue($this->customer)['token']);
        $this->context->clear();

        return $scan;
    };
    $this->redeemByScan = function (Reward $reward, ?CounterScan $scan = null): bool {
        $scan ??= ($this->scan)();
        $this->context->set($this->tenants->orgA, $this->tenants->a1);
        $redeemed = app(RedeemScan::class)->handle($scan, $this->staff, $reward)->redeemedNow;
        $this->context->clear();

        return $redeemed;
    };
    // A redemption with the customer's own tap, at a business of the reward's own organization: no scan.
    $this->redeemByTap = fn (Reward $reward, ?Business $at = null) => $this->context->bypass(fn () => Reward::query()->whereKey($reward->id)->update([
        'status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => ($at ?? $this->tenants->a1)->id,
        'redeemed_location_id' => $this->tenants->locationOf($at ?? $this->tenants->a1)->id,
    ]));
    $this->notices = fn () => $this->actingAs($this->customer)->get(route('app.cards'));
    $this->report = fn (bool $accepted): MessageSentReport => new MessageSentReport(new Request('POST', 'https://fcm.googleapis.com/fcm/send/1'), null, $accepted);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('tells the customer at once, on their phone, when staff redeem their reward with a scan', function (): void {
    ($this->phone)($this->customer);
    $this->customer->forceFill(['locale' => 'fr'])->save();

    expect(($this->redeemByScan)($this->reward))->toBeTrue();

    Notification::assertSentTo($this->customer, RewardRedeemed::class, function (RewardRedeemed $notification, array $channels, object $notifiable, ?string $locale): bool {
        // Built as the channel builds it: in the customer's language.
        app()->setLocale((string) $locale);
        $push = $notification->toWebPush($notifiable, $notification)->toArray();

        return $push['title'] === 'A1'
            && $push['tag'] === 'redeemed-'.$this->reward->id
            && $push['body'] === __('Your :reward was redeemed at the counter. Not you? Tell the staff.', ['reward' => $this->reward->reward_text], 'fr')
            && $push['data'] === ['url' => route('app.cards.show', $this->enrollment->id, false)];
    });
});

it('tells them once: a scan redeeming it again redeems nothing and says nothing', function (): void {
    ($this->phone)($this->customer);

    $scan = ($this->scan)();
    ($this->redeemByScan)($this->reward, $scan);
    expect(($this->redeemByScan)($this->reward, $scan))->toBeFalse();

    // Told already (a push accepted, or Got it): asked again, it says nothing more.
    $this->context->bypass(fn () => Reward::query()->whereKey($this->reward->id)->update(['redeem_notice_at' => now()]));
    app(PushScanRedemption::class)->handle($this->enrollment->id, $this->tenants->orgA->id, $this->tenants->a1->id, [$this->reward->id]);

    Notification::assertSentToTimes($this->customer, RewardRedeemed::class, 1);
});

it('tells nobody about a reward the customer redeemed with their own tap', function (): void {
    ($this->phone)($this->customer);
    ($this->redeemByTap)($this->reward);

    app(PushScanRedemption::class)->handle($this->enrollment->id, $this->tenants->orgA->id, $this->tenants->a1->id, [$this->reward->id]);

    Notification::assertNothingSent();
    ($this->notices)()->assertInertia(fn (Assert $page): Assert => $page->where('redeemNotices', []));
});

it('pushes nothing without a phone, without a VAPID key, or to an account anonymised since', function (): void {
    // No phone: the scan redemption pushes nothing (My cards tells them).
    ($this->redeemByScan)($this->reward);
    Notification::assertNothingSent();

    ($this->phone)($this->customer);
    $push = fn () => app(PushScanRedemption::class)->handle($this->enrollment->id, $this->tenants->orgA->id, $this->tenants->a1->id, [$this->reward->id]);

    config(['webpush.vapid.public_key' => null]);
    $push();
    Notification::assertNothingSent();

    config(['webpush.vapid.public_key' => 'BAppPublicKey']);
    $this->customer->forceFill(['anonymised_at' => now()])->save();
    $push();
    Notification::assertNothingSent();
});

it('ignores a reward of another enrollment, or of another organization, named in the event', function (): void {
    // Both redeemed by a scan before anyone had a phone: nothing told them yet.
    $scan = ($this->scan)();
    ($this->redeemByScan)($this->reward, $scan);
    $someoneElse = User::factory()->create();
    $theirs = $this->tenants->reward($this->tenants->enroll($someoneElse, $this->tenants->cardA));
    $this->context->bypass(fn () => Reward::query()->whereKey($theirs->id)->update([
        'status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a1->id, 'redeemed_scan_id' => $scan->id,
    ]));
    ($this->phone)($this->customer);
    ($this->phone)($someoneElse);
    $push = fn (int $organizationId, int $rewardId) => app(PushScanRedemption::class)
        ->handle($this->enrollment->id, $organizationId, $this->tenants->a1->id, [$rewardId]);

    $push($this->tenants->orgB->id, $this->reward->id);
    $push($this->tenants->orgA->id, $theirs->id);
    Notification::assertNothingSent();

    $push($this->tenants->orgA->id, $this->reward->id);
    Notification::assertSentToTimes($this->customer, RewardRedeemed::class, 1);
});

it('queues for a redemption by scan only: never for one by tap, so the tap stays fast, nor for a stamp', function (): void {
    $listener = app(SendRedeemNotices::class);

    expect($listener->shouldQueue(new EnrollmentChanged(1, 1, 1, [], [7], byScan: true)))->toBeTrue()
        ->and($listener->shouldQueue(new EnrollmentChanged(1, 1, 1, [], [7])))->toBeFalse()
        ->and($listener->shouldQueue(new EnrollmentChanged(1, 1, 1, [7], byScan: true)))->toBeFalse();
});

it('counts scan redemptions made before it shipped as told, so nobody gets a late "Not you?"', function (): void {
    ($this->redeemByScan)($this->reward);
    $migration = require database_path('migrations/2026_11_01_110046_add_redeem_notice_at_to_rewards_table.php');
    $migration->down();
    $migration->up();

    ($this->notices)()->assertInertia(fn (Assert $page): Assert => $page->where('redeemNotices', []));
    expect($this->context->bypass(fn () => Reward::query()->findOrFail($this->reward->id)->redeem_notice_at?->equalTo(now())))->toBeTrue();
});

it('shows My cards\' notice when no phone got the push, in the redeeming site\'s time, until Got it', function (): void {
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save());
    ($this->redeemByScan)($this->reward);

    ($this->notices)()->assertInertia(fn (Assert $page): Assert => $page->component('app/cards')->where('redeemNotices', [[
        'id' => $this->reward->id, 'reward' => $this->reward->reward_text, 'business' => 'A1', 'at' => ['day' => 'today', 'date' => '2026-11-01', 'time' => '14:42'],
    ]]));

    $this->actingAs($this->customer)->patch(route('rewards.notice.dismiss', $this->reward->id))->assertRedirect(route('app.cards'));

    ($this->notices)()->assertInertia(fn (Assert $page): Assert => $page->where('redeemNotices', []));
});

it('shows no notice once a phone\'s push service accepted the push, and keeps it when every phone refused it', function (): void {
    ($this->redeemByScan)($this->reward);
    $message = new RewardRedeemed($this->reward->id, $this->enrollment->id, 'A1', $this->reward->reward_text);

    event(new NotificationSent($this->customer, $message, WebPushChannel::class, [($this->report)(false)]));
    ($this->notices)()->assertInertia(fn (Assert $page): Assert => $page->has('redeemNotices', 1));

    event(new NotificationSent($this->customer, $message, WebPushChannel::class, [($this->report)(false), ($this->report)(true)]));
    ($this->notices)()->assertInertia(fn (Assert $page): Assert => $page->where('redeemNotices', []));
});

it('lets the customer dismiss only their own scan redemption\'s notice', function (): void {
    ($this->redeemByScan)($this->reward);
    $someoneElse = User::factory()->create();
    $tapped = $this->tenants->reward($this->tenants->enroll($this->customer, $this->tenants->cardB));
    ($this->redeemByTap)($tapped, $this->tenants->b1);

    $this->actingAs($someoneElse)->patch(route('rewards.notice.dismiss', $this->reward->id))->assertNotFound();
    $this->actingAs($this->customer)->patch(route('rewards.notice.dismiss', $tapped->id))->assertNotFound();

    ($this->notices)()->assertInertia(fn (Assert $page): Assert => $page->has('redeemNotices', 1));
});

it('lets a notice go after 30 days', function (): void {
    ($this->redeemByScan)($this->reward);

    $this->travel(30)->days();
    $this->travel(1)->minute();

    ($this->notices)()->assertInertia(fn (Assert $page): Assert => $page->where('redeemNotices', []));
});
