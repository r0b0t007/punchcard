<?php

declare(strict_types=1);

use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRequest;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Events\EnrollmentChanged;
use App\Listeners\SendRewardPushes;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\User;
use App\Notifications\RewardReady;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| "Your reward is ready", pushed (CHW-41, design 11d)
|--------------------------------------------------------------------------
|
| A reward unlocked away from the customer's own tap (a staff scan, a
| manual stamp, a bonus) is pushed to their phones, titled with the place,
| in their language, opening the card. Not after their own tap, whose
| screen (C3) already says it. Never when they turned "Rewards ready" off,
| have no phone, or their account is being deleted; never a reward no
| longer available or another card's; once per reward, however often its
| job runs.
|
*/

beforeEach(function (): void {
    config(['webpush.vapid.public_key' => 'BAppPublicKey', 'webpush.vapid.private_key' => 'app-private-key']);
    Notification::fake();
    $this->tenants = Tenants::make();
    $this->customer = User::factory()->create(['locale' => 'en']);
    $this->customer->updatePushSubscription('https://fcm.googleapis.com/fcm/send/phone-1', 'BPhoneKey', 'PhoneSecret', 'aes128gcm');
    $this->enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    // A reward the stamp of $source unlocked, at A1 (or the other card's own business).
    $this->unlocked = fn (StampSource $source, ?CardEnrollment $enrollment = null, array $attributes = [], ?Business $at = null): Reward => app(TenantContext::class)->bypass(function () use ($source, $enrollment, $attributes, $at): Reward {
        $enrollment ??= $this->enrollment;
        $stamp = $this->tenants->stamp($enrollment, $at ?? $this->tenants->a1, ['source' => $source]);

        return Reward::factory()->for($enrollment, 'enrollment')->create(['stamp_event_id' => $stamp->id, 'reward_text' => 'A free coffee', ...$attributes]);
    });
    $this->changed = fn (array $rewards, ?CardEnrollment $enrollment = null) => EnrollmentChanged::dispatch(
        ($enrollment ?? $this->enrollment)->id, $this->tenants->orgA->id, $this->tenants->a1->id, array_map(fn (Reward $reward): int => $reward->id, $rewards),
    );
});

it('tells the customer\'s phones about a reward unlocked away from their own tap', function (StampSource $source, bool $pushed): void {
    $reward = ($this->unlocked)($source);

    ($this->changed)([$reward]);

    $pushed ? Notification::assertSentTo($this->customer, RewardReady::class) : Notification::assertNothingSent();
    expect($reward->fresh()?->push_sent_at !== null)->toBe($pushed);
})->with([
    'a staff scan' => [StampSource::Qr, true],
    'a manual stamp' => [StampSource::Manual, true],
    'a bonus' => [StampSource::Bonus, true],
    'their own tap (C3 says it)' => [StampSource::Nfc, false],
]);

it('pushes nothing to a customer who turned rewards ready off, has no phone, or is being deleted', function (string $case): void {
    match ($case) {
        'rewards ready off' => $this->customer->forceFill(['push_rewards' => false])->save(),
        'no phone' => $this->customer->pushSubscriptions()->delete(),
        'being deleted' => $this->customer->forceFill(['anonymised_at' => now()])->save(),
        'no key for the app' => config(['webpush.vapid.public_key' => null]),
    };

    ($this->changed)([($this->unlocked)(StampSource::Qr)]);

    Notification::assertNothingSent();
})->with(['rewards ready off', 'no phone', 'being deleted', 'no key for the app']);

it('pushes a reward once, however often its job runs', function (): void {
    $reward = ($this->unlocked)(StampSource::Manual);

    ($this->changed)([$reward]);
    ($this->changed)([$reward]);

    Notification::assertSentToTimes($this->customer, RewardReady::class, 1);
});

it('pushes a reward once when two jobs read it at the same moment', function (): void {
    $reward = ($this->unlocked)(StampSource::Manual);
    $raced = new stdClass;
    $raced->done = false;
    // Right after this job reads the reward as not pushed yet, another job claims it.
    DB::listen(function (QueryExecuted $query) use ($reward, $raced): void {
        if (! $raced->done && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'push_sent_at')) {
            $raced->done = true;
            DB::table('rewards')->where('id', $reward->id)->update(['push_sent_at' => now()]);
        }
    });

    ($this->changed)([$reward]);

    Notification::assertNothingSent();
});

it('pushes nothing for a reward redeemed while its job was reading it', function (): void {
    $reward = ($this->unlocked)(StampSource::Qr);
    $raced = new stdClass;
    $raced->done = false;
    // Right after this job reads the reward as available, it is redeemed at the counter.
    DB::listen(function (QueryExecuted $query) use ($reward, $raced): void {
        if (! $raced->done && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'push_sent_at')) {
            $raced->done = true;
            DB::table('rewards')->where('id', $reward->id)->update(['status' => RewardStatus::Redeemed->value, 'redeemed_at' => now()]);
        }
    });

    ($this->changed)([$reward]);

    Notification::assertNothingSent();
});

it('pushes a reward a staff scan unlocked, through the stamp itself, and queues nothing after the customer\'s own tap', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $here = $this->tenants->locationOf($this->tenants->a1);
    app(TenantContext::class)->bypass(fn () => $this->enrollment->forceFill(['current_stamps' => $this->tenants->cardA->stamps_required - 1])->save());

    app(TenantContext::class)->bypass(fn () => app(AddStamps::class)->handle($this->enrollment->fresh(), StampRequest::qr($here, $staff, (string) Str::uuid())));

    Notification::assertSentToTimes($this->customer, RewardReady::class, 1);

    // The customer's own tap: its screen says it, nothing is even queued (the tap stays fast).
    Event::fake([EnrollmentChanged::class]);
    $stamper = $this->tenants->stamper($this->tenants->a1);
    app(TenantContext::class)->bypass(fn () => app(AddStamps::class)->handle($this->enrollment->fresh(), StampRequest::nfc($stamper, 1, at: now()->addHour())));
    Event::assertDispatched(EnrollmentChanged::class, fn (EnrollmentChanged $changed): bool => $changed->byOwnTap
        && ! app(SendRewardPushes::class)->shouldQueue($changed));
});

it('never pushes a reward no longer available, or one of another card', function (): void {
    $redeemed = ($this->unlocked)(StampSource::Qr, attributes: ['status' => RewardStatus::Redeemed]);
    $someoneElse = User::factory()->create();
    $someoneElse->updatePushSubscription('https://fcm.googleapis.com/fcm/send/their-phone', 'BTheirKey', 'TheirSecret');
    $theirs = ($this->unlocked)(StampSource::Qr, $this->tenants->enroll($someoneElse, $this->tenants->cardB), at: $this->tenants->b1);

    // An event of this card naming the other card's reward (another organization's) pushes it to nobody.
    ($this->changed)([$redeemed, $theirs]);

    Notification::assertNothingSent();
    expect($theirs->fresh()?->push_sent_at)->toBeNull();
});

it('says the place and the reward in the customer\'s language, and opens their card', function (): void {
    $this->customer->forceFill(['locale' => 'fr'])->save();
    $reward = ($this->unlocked)(StampSource::Qr);

    ($this->changed)([$reward]);

    Notification::assertSentTo($this->customer, RewardReady::class, function (RewardReady $notification, array $channels, User $notifiable, ?string $locale) use ($reward): bool {
        App::setLocale((string) $locale);
        $message = $notification->toWebPush($notifiable, $notification)->toArray();
        App::setLocale('en');

        return $channels === [WebPushChannel::class] && $locale === 'fr' && $message['title'] === 'A1'
            && $message['body'] === 'Votre récompense est prête : A free coffee'
            && $message['tag'] === 'reward-'.$reward->id && $message['lang'] === 'fr'
            && $message['data'] === ['url' => route('app.cards.show', $this->enrollment->id, false)];
    });
});

it('never names a business of another organization', function (): void {
    $reward = ($this->unlocked)(StampSource::Qr);

    // An event naming B1 (organization B) for a card of A: no place at all (the phone shows punchcard's name, sw-push.ts), never B1.
    EnrollmentChanged::dispatch($this->enrollment->id, $this->tenants->orgA->id, $this->tenants->b1->id, [$reward->id]);

    Notification::assertSentTo($this->customer, RewardReady::class, fn (RewardReady $notification, array $channels, User $notifiable): bool => ! array_key_exists('title', $notification->toWebPush($notifiable, $notification)->toArray()));
});

it('is queued, and only for a change that unlocked a reward', function (): void {
    Event::fake();
    Event::assertListening(EnrollmentChanged::class, SendRewardPushes::class);

    $listener = app(SendRewardPushes::class);
    expect($listener->shouldQueue(new EnrollmentChanged(1, 1, 1, [7])))->toBeTrue()
        ->and($listener->shouldQueue(new EnrollmentChanged(1, 1, 1, [], [7])))->toBeFalse()
        ->and($listener->shouldQueue(new EnrollmentChanged(1, 1, 1, [7], byOwnTap: true)))->toBeFalse();
});
