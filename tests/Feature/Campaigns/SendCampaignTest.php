<?php

declare(strict_types=1);

use App\Actions\Campaigns\CampaignLimit;
use App\Actions\Campaigns\DeliverCampaign;
use App\Actions\Campaigns\SendCampaignBatch;
use App\Enums\BusinessRole;
use App\Enums\CampaignSegment;
use App\Enums\CampaignStatus;
use App\Jobs\PushCampaign;
use App\Jobs\PushCampaignBatch;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\CardEnrollment;
use App\Models\Follow;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Notifications\CampaignMessage;
use App\Notifications\RewardReady;
use App\Support\Campaigns\CampaignText;
use App\Support\Tenancy\TenantContext;
use GuzzleHttp\Psr7\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Sending a campaign (CHW-42, B9)
|--------------------------------------------------------------------------
|
| Whoever runs the business sends its followers a notification, at most 3 in
| any 7 days. It goes through the queue, in the business's tenant, to those
| with a phone, once: titled with the business's name, opening the card
| chosen (their own enrollment of it).
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    config(['webpush.vapid.public_key' => 'BAppPublicKey', 'webpush.vapid.private_key' => 'app-private-key']);
    Notification::fake();
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);

    $this->follower = function (bool $phone = true, ?LoyaltyCard $card = null, ?Business $at = null): User {
        $card ??= $this->tenants->cardA;
        $customer = User::factory()->create();
        $enrollment = $this->tenants->enroll($customer, $card);
        app(TenantContext::class)->bypass(fn () => (new Follow)->forceFill(['organization_id' => $card->organization_id, 'user_id' => $customer->id, 'notify' => true])->save());
        $this->tenants->stamp($enrollment, $at ?? $this->tenants->a1);

        if ($phone) {
            $customer->updatePushSubscription('https://fcm.googleapis.com/fcm/send/'.$customer->id, 'BKey', 'Secret');
        }

        return $customer;
    };
    $this->send = fn (array $input = [], ?User $as = null) => $this->actingAs($as ?? $this->owner)->post(route('campaigns.store'), [
        'idempotency_key' => (string) Str::uuid(), 'segment' => 'all', 'title' => 'Slow Tuesday?', 'body' => 'Two stamps for every coffee until 4 pm today.', 'card_id' => $this->tenants->cardA->id, ...$input,
    ]);
});

it('sends the business\'s followers with a phone a notification titled with its name, opening their own card', function (): void {
    $reached = ($this->follower)();
    $noPhone = ($this->follower)(phone: false);

    ($this->send)()->assertRedirect(route('campaigns.index'))->assertSessionHasNoErrors();

    $campaign = app(TenantContext::class)->bypass(fn (): Campaign => Campaign::query()->sole());
    expect($campaign->only(['business_id', 'organization_id', 'created_by', 'audience_count']))->toBe([
        'business_id' => $this->tenants->a1->id, 'organization_id' => $this->tenants->orgA->id, 'created_by' => $this->owner->id, 'audience_count' => 1,
    ])->and($campaign->status)->toBe(CampaignStatus::Sent)
        ->and($campaign->sent_at)->not->toBeNull();

    $enrollment = app(TenantContext::class)->bypass(fn (): CardEnrollment => CardEnrollment::query()->where('user_id', $reached->id)->sole());
    Notification::assertSentTo($reached, CampaignMessage::class, function (CampaignMessage $message) use ($reached, $enrollment): bool {
        $push = $message->toWebPush($reached, $message)->toArray();

        return $push['title'] === 'A1 · Slow Tuesday?'
            && $push['body'] === 'Two stamps for every coffee until 4 pm today.'
            && $push['tag'] === 'campaign-'.$message->campaignId
            && $push['data'] === ['url' => route('app.cards.show', $enrollment->id, false)];
    });
    Notification::assertNotSentTo($noPhone, CampaignMessage::class);
});

it('never reaches who only came in to a sister franchisee, or another organization\'s followers', function (): void {
    $here = ($this->follower)();
    $sisterOnly = ($this->follower)(at: $this->tenants->a2);
    $otherOrganization = ($this->follower)(card: $this->tenants->cardB, at: $this->tenants->b1);

    ($this->send)()->assertSessionHasNoErrors();

    Notification::assertSentTo($here, CampaignMessage::class);
    Notification::assertNotSentTo([$sisterOnly, $otherOrganization], CampaignMessage::class);
});

it('makes one campaign of a double tap on Send', function (): void {
    $reached = ($this->follower)();
    $key = (string) Str::uuid();

    ($this->send)(['idempotency_key' => $key])->assertSessionHasNoErrors();
    ($this->send)(['idempotency_key' => $key])->assertRedirect(route('campaigns.index'))->assertSessionHasNoErrors();

    expect(app(TenantContext::class)->bypass(fn (): int => Campaign::query()->count()))->toBe(1);
    Notification::assertSentToTimes($reached, CampaignMessage::class, 1);
});

it('hands the audience with a phone out in batches', function (): void {
    Queue::fake([PushCampaignBatch::class]);
    $first = ($this->follower)();
    $second = ($this->follower)();
    ($this->follower)(phone: false);

    ($this->send)()->assertSessionHasNoErrors();

    Queue::assertPushed(PushCampaignBatch::class, fn (PushCampaignBatch $batch): bool => $batch->userIds === [$first->id, $second->id]);
    $campaign = app(TenantContext::class)->bypass(fn (): Campaign => Campaign::query()->sole());
    expect($campaign->status)->toBe(CampaignStatus::Sent);

    (new PushCampaignBatch($campaign->id, [$first->id, $second->id]))->handle(app(SendCampaignBatch::class));
    Notification::assertSentTo([$first, $second], CampaignMessage::class);
});

it('counts a follower once a push service accepted their notification, never a refused one', function (): void {
    $campaign = app(TenantContext::class)->bypass(fn (): Campaign => Campaign::factory()->for($this->tenants->a1)->create());
    $message = new CampaignMessage($campaign->id, 'A1 · Slow Tuesday?', 'Two stamps.', null);
    $report = fn (bool $accepted): MessageSentReport => new MessageSentReport(new Request('POST', 'https://fcm.googleapis.com/fcm/send/1'), null, $accepted);
    $customer = User::factory()->create();

    event(new NotificationSent($customer, $message, WebPushChannel::class, [$report(false), $report(true)]));
    event(new NotificationSent($customer, $message, WebPushChannel::class, [$report(false)]));
    event(new NotificationSent($customer, $message, WebPushChannel::class, []));
    event(new NotificationSent($customer, new RewardReady(1, 1, 'A1', 'A coffee'), WebPushChannel::class, [$report(true)]));

    expect(app(TenantContext::class)->bypass(fn (): int => $campaign->refresh()->sent_count))->toBe(1);
});

it('reaches its audience as it is when it goes: not who stopped following meanwhile', function (): void {
    $stopped = ($this->follower)();
    $campaign = app(TenantContext::class)->bypass(fn (): Campaign => Campaign::factory()->for($this->tenants->a1)->create());
    app(TenantContext::class)->bypass(fn () => Follow::query()->where('user_id', $stopped->id)->update(['notify' => false]));

    app(DeliverCampaign::class)->handle($campaign->id);

    Notification::assertNothingSent();
});

it('sends nothing for a canceled campaign, and cancels one whose delivery failed before it went', function (): void {
    ($this->follower)();
    [$canceled, $failed, $sent] = app(TenantContext::class)->bypass(fn (): array => [
        Campaign::factory()->for($this->tenants->a1)->create(['status' => CampaignStatus::Canceled]),
        Campaign::factory()->for($this->tenants->a1)->create(),
        Campaign::factory()->for($this->tenants->a1)->create(['status' => CampaignStatus::Sent, 'sent_at' => now()]),
    ]);

    app(DeliverCampaign::class)->handle($canceled->id);
    (new PushCampaign($failed->id))->failed(new RuntimeException('The business closed.'));
    (new PushCampaign($sent->id))->failed(new RuntimeException('A late failure.'));

    Notification::assertNothingSent();
    expect(app(TenantContext::class)->bypass(fn (): array => [$canceled->refresh()->status, $failed->refresh()->status, $sent->refresh()->status]))
        ->toBe([CampaignStatus::Canceled, CampaignStatus::Canceled, CampaignStatus::Sent]);
});

it('marks a delivery that failed midway sent, with what it handed out, never stuck', function (): void {
    $midway = app(TenantContext::class)->bypass(fn (): Campaign => Campaign::factory()->for($this->tenants->a1)->create());
    app(TenantContext::class)->bypass(fn () => Campaign::query()->whereKey($midway->id)->update(['status' => CampaignStatus::Sending, 'sent_count' => 4]));

    (new PushCampaign($midway->id))->failed(new RuntimeException('Timed out.'));

    $midway = app(TenantContext::class)->bypass(fn (): Campaign => $midway->refresh());
    expect($midway->status)->toBe(CampaignStatus::Sent)
        ->and($midway->sent_at)->not->toBeNull()
        ->and($midway->sent_count)->toBe(4);
});

it('opens My cards for a follower without the card chosen', function (): void {
    $reached = ($this->follower)();
    $otherCard = app(TenantContext::class)->bypass(function (): LoyaltyCard {
        $card = LoyaltyCard::factory()->for($this->tenants->orgA)->create();
        $card->businesses()->attach($this->tenants->a1->id);

        return $card;
    });

    ($this->send)(['card_id' => $otherCard->id])->assertSessionHasNoErrors();

    Notification::assertSentTo($reached, CampaignMessage::class, fn (CampaignMessage $message): bool => $message->toWebPush($reached, $message)->toArray()['data'] === ['url' => route('app.cards', [], false)]);
});

it('opens My cards when no card is chosen', function (): void {
    $reached = ($this->follower)();

    ($this->send)(['card_id' => null])->assertSessionHasNoErrors();

    Notification::assertSentTo($reached, CampaignMessage::class, fn (CampaignMessage $message): bool => $message->toWebPush($reached, $message)->toArray()['data'] === ['url' => route('app.cards', [], false)]);
});

it('queues the delivery in the business\'s tenant, never in the request', function (): void {
    Queue::fake();
    ($this->follower)();

    ($this->send)()->assertSessionHasNoErrors();

    Queue::assertPushed(PushCampaign::class, 1);
    Notification::assertNothingSent();
});

it('delivers a campaign once, even when its job runs again', function (): void {
    $reached = ($this->follower)();
    ($this->send)();
    $campaign = app(TenantContext::class)->bypass(fn (): Campaign => Campaign::query()->sole());

    app(DeliverCampaign::class)->handle($campaign->id);

    Notification::assertSentToTimes($reached, CampaignMessage::class, 1);
});

it('allows 3 campaigns in any 7 days, canceled ones aside', function (): void {
    ($this->follower)();
    ($this->send)();
    ($this->send)();
    app(TenantContext::class)->bypass(fn () => Campaign::factory()->for($this->tenants->a1)->create(['status' => CampaignStatus::Canceled]));
    ($this->send)()->assertSessionHasNoErrors();

    ($this->send)()->assertSessionHasErrors('campaign');

    // A sister franchisee has its own 3.
    ($this->follower)(at: $this->tenants->a2);
    $sisterOwner = $this->tenants->member(User::factory()->create(), $this->tenants->a2, BusinessRole::Owner);
    ($this->send)(as: $sisterOwner)->assertSessionHasNoErrors();

    $this->travel(7)->days();
    ($this->send)()->assertSessionHasNoErrors();
    expect(app(TenantContext::class)->bypass(fn (): int => Campaign::query()->where('business_id', $this->tenants->a1->id)->where('status', '!=', CampaignStatus::Canceled)->count()))->toBe(4);
});

it('counts the campaigns scheduled in the next 7 days too', function (): void {
    ($this->follower)();
    app(TenantContext::class)->bypass(fn () => Campaign::factory()->for($this->tenants->a1)->count(3)->create(['send_at' => now()->addDays(6)]));

    ($this->send)()->assertSessionHasErrors('campaign');

    // Still 7 days from them on the 12th day; out of their week on the 13th.
    $this->travel(12)->days();
    ($this->send)()->assertSessionHasErrors('campaign');
    $this->travel(1)->days();
    ($this->send)()->assertSessionHasNoErrors();
});

it('leaves out the campaigns scheduled 7 days ahead or more', function (): void {
    ($this->follower)();
    // To the second, as the database keeps it: exactly 7 days ahead is the next week.
    $this->freezeSecond();
    app(TenantContext::class)->bypass(fn () => Campaign::factory()->for($this->tenants->a1)->count(3)->create(['send_at' => now()->addDays(7)]));

    ($this->send)()->assertSessionHasNoErrors();
});

it('allows a campaign whose every week stays within 3, though more go within 7 days either side', function (): void {
    ($this->follower)();
    app(TenantContext::class)->bypass(fn () => collect([-6, -5, 6])->each(fn (int $days) => Campaign::factory()->for($this->tenants->a1)->create(['send_at' => now()->addDays($days)])));

    ($this->send)()->assertSessionHasNoErrors();

    // Now [-6, +1) holds -6, -5 and today's: full. Another one today is refused.
    ($this->send)()->assertSessionHasErrors('campaign');
});

it('says when the next campaign can go, later ones already scheduled counted in', function (): void {
    // One clock for the campaigns and the limit: a second ticking over between them is not the next week.
    $this->freezeSecond();
    app(TenantContext::class)->bypass(function (): void {
        Campaign::factory()->for($this->tenants->a1)->count(3)->create(['send_at' => now()->subDay()]);
        Campaign::factory()->for($this->tenants->a1)->count(3)->create(['send_at' => now()->addDays(12)]);
    });
    $limit = fn () => app(CampaignLimit::class)->handle($this->tenants->a1, now());

    // Yesterday's leave in 6 days, but the three in 12 days fill that week: in 19 days, theirs leave too.
    expect($limit()['used'])->toBe(3)
        ->and($limit()['nextAt']?->toDateTimeString())->toBe(now()->addDays(19)->startOfSecond()->toDateTimeString());

    app(TenantContext::class)->bypass(fn () => Campaign::query()->where('send_at', '>', now())->update(['status' => CampaignStatus::Canceled]));
    expect($limit()['nextAt']?->toDateTimeString())->toBe(now()->addDays(6)->startOfSecond()->toDateTimeString());
});

it('never delivers a campaign before its time', function (): void {
    $reached = ($this->follower)();
    $later = app(TenantContext::class)->bypass(fn (): Campaign => Campaign::factory()->for($this->tenants->a1)->create(['send_at' => now()->addHour()]));

    app(DeliverCampaign::class)->handle($later->id);

    Notification::assertNotSentTo($reached, CampaignMessage::class);
    expect(app(TenantContext::class)->bypass(fn (): CampaignStatus => $later->refresh()->status))->toBe(CampaignStatus::Scheduled);
});

it('refuses staff, and a card this business does not honour', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff);
    ($this->send)(as: $staff)->assertForbidden();

    $sisterOnly = app(TenantContext::class)->bypass(function (): LoyaltyCard {
        $card = LoyaltyCard::factory()->for($this->tenants->orgA)->create();
        $card->businesses()->attach($this->tenants->a2->id);

        return $card;
    });
    ($this->send)(['card_id' => $sisterOnly->id])->assertSessionHasErrors('card_id');
    ($this->send)(['card_id' => $this->tenants->cardB->id])->assertSessionHasErrors('card_id');

    expect(app(TenantContext::class)->bypass(fn (): int => Campaign::query()->count()))->toBe(0);
});

it('checks the audience, the title against the business\'s name, and the message', function (array $input, string $error): void {
    ($this->send)($input)->assertSessionHasErrors($error);
})->with([
    'no idempotency key' => [['idempotency_key' => 'twice'], 'idempotency_key'],
    'an unknown audience' => [['segment' => 'birthday'], 'segment'],
    'no title' => [['title' => ''], 'title'],
    'a title too long beside the name' => [['title' => str_repeat('a', 36)], 'title'],
    'no message' => [['body' => ''], 'body'],
    'a message too long' => [['body' => str_repeat('a', 121)], 'body'],
]);

it('shares 40 characters between the business\'s name and the title, the title keeping at least 15', function (): void {
    expect(CampaignText::titleRoom('A1'))->toBe(35)
        ->and(CampaignText::titleRoom(str_repeat('n', 30)))->toBe(15)
        ->and(CampaignText::notificationTitle('Café Hafa', 'Slow Tuesday?'))->toBe('Café Hafa · Slow Tuesday?')
        ->and(CampaignText::notificationTitle(str_repeat('n', 30), str_repeat('t', 15)))->toBe(str_repeat('n', 21).'… · '.str_repeat('t', 15))
        ->and(mb_strlen(CampaignText::notificationTitle(str_repeat('n', 30), str_repeat('t', 15))))->toBe(40);
});

it('refuses a campaign nobody would get, and keeps the week\'s campaigns: no phone, or no VAPID key on the server', function (): void {
    ($this->follower)(phone: false);
    ($this->send)()->assertSessionHasErrors(['campaign' => 'Nobody in this audience has notifications on a phone yet.']);

    ($this->follower)();
    config(['webpush.vapid.public_key' => null]);
    ($this->send)()->assertSessionHasErrors('campaign');

    Notification::assertNothingSent();
    expect(app(TenantContext::class)->bypass(fn (): int => Campaign::query()->count()))->toBe(0);
});

it('keeps the segment chosen', function (): void {
    ($this->follower)();
    ($this->send)(['segment' => 'top_10'])->assertSessionHasNoErrors();

    expect(app(TenantContext::class)->bypass(fn (): CampaignSegment => Campaign::query()->sole()->segment))->toBe(CampaignSegment::Top);
});
