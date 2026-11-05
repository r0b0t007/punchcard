<?php

declare(strict_types=1);

use App\Actions\Automations\RunAutomations;
use App\Actions\Automations\SendAutomationMessages;
use App\Actions\Customers\SummariseCustomer;
use App\Enums\AutomationTrigger;
use App\Enums\RewardStatus;
use App\Features\Automations;
use App\Models\Automation;
use App\Models\AutomationSend;
use App\Models\Business;
use App\Models\Follow;
use App\Models\Reward;
use App\Models\User;
use App\Notifications\AutomationMessage;
use App\Notifications\RewardReady;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Sending an automation message (CHW-46)
|--------------------------------------------------------------------------
|
| Each claimed message goes to the customer's phones through the queue, in
| the business's tenant: ours in their language, or the owner's as written,
| opening the card at that business or the one holding the reward. Marked
| sent once a phone's push service accepted it.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-04 12:00:00');
    config(['webpush.vapid.public_key' => 'BAppPublicKey', 'webpush.vapid.private_key' => 'app-private-key']);
    Notification::fake();
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    Feature::for($this->tenants->a1)->activate(Automations::class);

    // A follower of A1 going quiet, with a phone, who reads French.
    $this->customer = User::factory()->create(['locale' => 'fr']);
    $this->enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->context->bypass(fn () => (new Follow)->forceFill(['organization_id' => $this->tenants->orgA->id, 'user_id' => $this->customer->id, 'notify' => true])->save());
    $this->stamp = $this->tenants->stamp($this->enrollment, $this->tenants->a1, ['created_at' => now()->subDays(20)]);
    app(SummariseCustomer::class)->handle($this->tenants->a1->id, $this->tenants->orgA->id, $this->customer->id);
    $this->customer->updatePushSubscription('https://fcm.googleapis.com/fcm/send/1', 'BKey', 'Secret');

    $this->automation = fn (AutomationTrigger $trigger, array $attributes = []): Automation => $this->context->bypass(fn (): Automation => Automation::factory()
        ->create(['business_id' => $this->tenants->a1->id, 'trigger' => $trigger, ...$attributes]));
    $this->run = fn (): array => app(RunAutomations::class)->handle(CarbonImmutable::now());

    // Claimed now, as a run would, for the queue to send.
    $this->winBackClaimed = function (): AutomationSend {
        $automation = ($this->automation)(AutomationTrigger::WinBack);
        $quietAt = $this->context->bypass(fn () => DB::table('business_customers')->where('user_id', $this->customer->id)->value('quiet_at'));

        return $this->context->bypass(fn (): AutomationSend => AutomationSend::factory()->create([
            'automation_id' => $automation->id, 'user_id' => $this->customer->id, 'occasion' => 'quiet:'.CarbonImmutable::parse($quietAt)->toDateString(),
        ]));
    };
    $this->rewardClaimed = function (): Reward {
        $automation = ($this->automation)(AutomationTrigger::RewardReminder, ['days' => 7]);
        $reward = $this->context->bypass(fn (): Reward => Reward::factory()->for($this->enrollment, 'enrollment')
            ->create(['stamp_event_id' => $this->stamp->id, 'unlocked_at' => now()->subDays(8), 'reward_text' => 'Un café offert']));
        $this->context->bypass(fn () => AutomationSend::factory()->create(['automation_id' => $automation->id, 'user_id' => $this->customer->id, 'occasion' => 'reward:'.$reward->id]));

        return $reward;
    };
    // The queued job's work, in a business's tenant (A1's by default), as QueuedTenant runs it.
    $this->deliver = function (?Business $in = null): void {
        $in ??= $this->tenants->a1;
        $previous = $this->context->snapshot();

        try {
            $this->context->set($this->tenants->orgA, $in);
            app(SendAutomationMessages::class)->handle($this->context->bypass(fn (): array => AutomationSend::query()->pluck('id')->all()));
        } finally {
            $this->context->restore($previous);
        }
    };

    // The push as the customer's phone gets it, in the language it was sent in.
    $this->pushed = function (): array {
        $pushed = new stdClass;
        $pushed->all = [];
        Notification::assertSentTo($this->customer, AutomationMessage::class, function (AutomationMessage $message, array $channels, User $notifiable, ?string $locale) use ($pushed): bool {
            $previous = App::getLocale();
            App::setLocale((string) $locale);
            $pushed->all[] = $message->toWebPush($notifiable, $message)->toArray();
            App::setLocale($previous);

            return true;
        });

        return $pushed->all;
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('sends our win-back in the customer\'s language, titled with the business, opening their card there', function (): void {
    ($this->automation)(AutomationTrigger::WinBack);

    ($this->run)();

    expect(($this->pushed)())->toHaveCount(1)
        ->and(($this->pushed)()[0])->toMatchArray([
            'title' => 'Vous nous manquez chez A1',
            'body' => 'Revenez vite : votre carte vous attend.',
            'tag' => 'automation-win_back-'.$this->tenants->a1->id,
            'lang' => 'fr',
            'dir' => 'ltr',
            'data' => ['url' => route('app.cards.show', $this->enrollment->id, false)],
        ]);
});

it('sends the owner\'s own message as written, in its own direction', function (): void {
    ($this->automation)(AutomationTrigger::WinBack, ['title' => 'On vous attend', 'body' => 'قهوتك بانتظارك']);

    ($this->run)();

    expect(($this->pushed)()[0])->toMatchArray(['title' => 'A1 · On vous attend', 'body' => 'قهوتك بانتظارك', 'dir' => 'auto']);
});

it('reminds of the reward by name, opening the card that holds it', function (): void {
    $reward = ($this->rewardClaimed)();

    ($this->deliver)();

    expect(($this->pushed)()[0])->toMatchArray([
        'title' => 'Votre récompense vous attend chez A1',
        'body' => 'N\'oubliez pas : Un café offert est à vous.',
        'data' => ['url' => route('app.cards.show', $reward->enrollment_id, false)],
    ]);
});

it('never reminds of a reward used or expired since, someone else\'s, another organization\'s, or with rewards ready turned off', function (string $case): void {
    $reward = ($this->rewardClaimed)();
    $send = $this->context->bypass(fn (): AutomationSend => AutomationSend::query()->sole());
    // Another customer's reward of A, and one of B: what a send's occasion must never reach.
    $someoneElses = fn (): Reward => $this->context->bypass(fn (): Reward => Reward::factory()
        ->for($this->tenants->enroll(User::factory()->create(), $this->tenants->cardA), 'enrollment')->create());
    $ofB = fn (): Reward => $this->context->bypass(fn (): Reward => Reward::factory()
        ->for($this->tenants->enroll($this->customer, $this->tenants->cardB), 'enrollment')->create());
    // Claimed for that reward instead: a claim's occasion never changes.
    $pointAt = fn (Reward $other) => $this->context->bypass(function () use ($send, $other): void {
        $send->delete();
        AutomationSend::factory()->create(['automation_id' => $send->automation_id, 'user_id' => $this->customer->id, 'occasion' => 'reward:'.$other->id]);
    });

    match ($case) {
        'used' => $this->context->bypass(fn () => DB::table('rewards')->where('id', $reward->id)
            ->update(['status' => RewardStatus::Redeemed->value, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a1->id])),
        'expired' => $this->context->bypass(fn () => DB::table('rewards')->where('id', $reward->id)->update(['expires_at' => now()->subMinute()])),
        'someone else\'s' => $pointAt($someoneElses()),
        'another organization\'s' => $pointAt($ofB()),
        'rewards ready off' => $this->customer->forceFill(['push_rewards' => false])->save(),
        'account anonymised' => $this->customer->forceFill(['anonymised_at' => now()])->save(),
    };

    ($this->deliver)();

    Notification::assertNothingSent();
})->with(['used', 'expired', 'someone else\'s', 'another organization\'s', 'rewards ready off', 'account anonymised']);

it('never sends what no longer holds when the queue gets to it', function (string $case): void {
    $send = ($this->winBackClaimed)();

    match ($case) {
        'already sent' => $this->context->bypass(fn (): bool => $send->markSent(now())),
        'account anonymised' => $this->customer->forceFill(['anonymised_at' => now()])->save(),
        'automation turned off' => $this->context->bypass(fn () => DB::table('automations')->update(['active' => false])),
        'automations stopped' => Feature::for($this->tenants->a1)->deactivate(Automations::class),
        'unfollowed' => $this->context->bypass(fn () => DB::table('follows')->where('user_id', $this->customer->id)->update(['notify' => false])),
        'came back' => $this->context->bypass(fn () => DB::table('business_customers')->where('user_id', $this->customer->id)->update(['quiet_at' => now()->addDays(20)])),
        'in another quiet spell' => $this->context->bypass(fn () => DB::table('business_customers')->where('user_id', $this->customer->id)->update(['quiet_at' => now()->subHour()])),
        'claimed over an hour ago' => Carbon::setTestNow(now()->addMinutes(61)),
        'now past 22:00' => Carbon::setTestNow('2026-11-04 21:00:00'),
    };

    ($this->deliver)();

    Notification::assertNothingSent();
})->with(['already sent', 'account anonymised', 'automation turned off', 'automations stopped', 'unfollowed', 'came back', 'in another quiet spell', 'claimed over an hour ago', 'now past 22:00']);

it('sends only its own business\'s sends, and only in a business\'s tenant', function (): void {
    $send = ($this->winBackClaimed)();

    // A2 sends now too: only the send's business keeps it from A2's job.
    Feature::for($this->tenants->a2)->activate(Automations::class);
    ($this->deliver)($this->tenants->a2);
    Notification::assertNothingSent();

    expect(fn () => app(SendAutomationMessages::class)->handle([$send->id]))->toThrow(LogicException::class, 'tenant');
});

it('marks a send sent once a phone\'s push service accepted it, never for a refused one', function (): void {
    ($this->automation)(AutomationTrigger::WinBack);
    $send = $this->context->bypass(fn (): AutomationSend => AutomationSend::factory()->create(['automation_id' => Automation::query()->value('id'), 'user_id' => $this->customer->id]));
    $message = new AutomationMessage($send->id, AutomationTrigger::WinBack, $this->tenants->a1->id, 'A1', null, null, null);
    $report = fn (bool $accepted): MessageSentReport => new MessageSentReport(new Request('POST', 'https://fcm.googleapis.com/fcm/send/1'), null, $accepted);
    $sentAt = fn (): mixed => $this->context->bypass(fn () => AutomationSend::query()->whereKey($send->id)->value('sent_at'));

    event(new NotificationSent($this->customer, $message, WebPushChannel::class, [$report(false)]));
    event(new NotificationSent($this->customer, new RewardReady(1, 1, 'A1', 'A coffee'), WebPushChannel::class, [$report(true)]));

    expect($sentAt())->toBeNull();

    event(new NotificationSent($this->customer, $message, WebPushChannel::class, [$report(false), $report(true)]));

    expect($sentAt())->not->toBeNull();
});
