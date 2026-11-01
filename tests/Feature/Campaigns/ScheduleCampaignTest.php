<?php

declare(strict_types=1);

use App\Actions\Campaigns\DispatchDueCampaigns;
use App\Enums\BusinessRole;
use App\Enums\CampaignStatus;
use App\Http\Middleware\SetTenant;
use App\Jobs\PushCampaign;
use App\Models\Campaign;
use App\Models\Follow;
use App\Models\User;
use App\Notifications\CampaignMessage;
use App\Support\Tenancy\QueuedTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Scheduling a campaign, and canceling it (CHW-42, part 3)
|--------------------------------------------------------------------------
|
| Later: a day and time in the business's time, a minute to 30 days ahead,
| within the week's limit at that moment. The scheduler queues it once due,
| in its business's tenant, and cancels one an hour late rather than send it
| late. Whoever runs the business cancels a scheduled one until it goes.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-04 10:02:00');
    config(['webpush.vapid.public_key' => 'BAppPublicKey', 'webpush.vapid.private_key' => 'app-private-key']);
    Notification::fake();
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    // A fixed offset (UTC+4, no daylight saving), the same in every tz database.
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save());
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $this->as = fn (User $user) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'business:'.$this->tenants->a1->id]);

    $this->follower = User::factory()->create();
    $enrollment = $this->tenants->enroll($this->follower, $this->tenants->cardA);
    $this->context->bypass(fn () => (new Follow)->forceFill(['organization_id' => $this->tenants->orgA->id, 'user_id' => $this->follower->id, 'notify' => true])->save());
    $this->tenants->stamp($enrollment, $this->tenants->a1);
    $this->follower->updatePushSubscription('https://fcm.googleapis.com/fcm/send/1', 'BKey', 'Secret');

    $this->schedule = fn (string $on, string $time, array $input = []) => ($this->as)($this->owner)->post(route('campaigns.store'), [
        'idempotency_key' => (string) Str::uuid(), 'segment' => 'all', 'title' => 'Slow Tuesday?', 'body' => 'Two stamps.',
        'card_id' => $this->tenants->cardA->id, 'when' => 'later', 'send_on' => $on, 'send_time' => $time, ...$input,
    ]);
    $this->campaign = fn (array $values = []): Campaign => $this->context->bypass(fn (): Campaign => Campaign::factory()->for($this->tenants->a1)->create($values));
    $this->status = fn (Campaign $campaign): CampaignStatus => $this->context->bypass(fn (): CampaignStatus => $campaign->refresh()->status);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('schedules a campaign for a day and time in the business\'s time, and queues nothing yet', function (): void {
    Queue::fake();

    ($this->schedule)('2026-11-05', '09:00')
        ->assertRedirect(route('campaigns.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Scheduled for 1 person.');

    $campaign = $this->context->bypass(fn (): Campaign => Campaign::query()->sole());
    expect($campaign->status)->toBe(CampaignStatus::Scheduled)
        ->and($campaign->send_at->toDateTimeString())->toBe('2026-11-05 05:00:00');
    Queue::assertNothingPushed();
});

it('takes a time from a minute ahead to 30 days ahead', function (string $on, string $time, bool $allowed): void {
    $response = ($this->schedule)($on, $time);

    $allowed ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('send_time');
})->with([
    'earlier today, in the business\'s time' => ['2026-11-04', '14:00', false],
    'two minutes ahead' => ['2026-11-04', '14:04', true],
    'in 30 days' => ['2026-12-04', '14:00', true],
    'in 31 days' => ['2026-12-05', '14:03', false],
]);

it('counts the week\'s limit at the moment a campaign goes', function (): void {
    ($this->campaign)(['status' => CampaignStatus::Sent, 'sent_at' => now()->subDay(), 'send_at' => now()->subDay()]);
    ($this->campaign)(['status' => CampaignStatus::Sent, 'sent_at' => now()->subDay(), 'send_at' => now()->subDay()]);
    ($this->campaign)(['status' => CampaignStatus::Sent, 'sent_at' => now()->subDay(), 'send_at' => now()->subDay()]);

    ($this->schedule)('2026-11-05', '09:00')->assertSessionHasErrors('campaign');
    // In 8 days those three have left its week.
    ($this->schedule)('2026-11-12', '09:00')->assertSessionHasNoErrors();
});

it('queues each due campaign inside its business\'s tenant, and delivers it once', function (): void {
    $due = ($this->campaign)(['send_at' => now()->subMinute()]);
    $later = ($this->campaign)(['send_at' => now()->addHour()]);
    $canceled = ($this->campaign)(['send_at' => now()->subMinute(), 'status' => CampaignStatus::Canceled]);
    $tenants = [];
    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$tenants): void {
        $tenants[] = $event->job->payload()[QueuedTenant::PAYLOAD_KEY] ?? null;
    });

    expect(app(DispatchDueCampaigns::class)->handle(now()))->toBe(['queued' => 1, 'missed' => 0])
        // The delivery, and the batch it hands out, both in the business's tenant.
        ->and($tenants)->toBe(array_fill(0, 2, ['organization' => $this->tenants->orgA->id, 'business' => $this->tenants->a1->id]))
        // The scheduler's own context is left as it was: none.
        ->and($this->context->organizationId())->toBeNull()
        ->and(($this->status)($due))->toBe(CampaignStatus::Sent)
        ->and(($this->status)($later))->toBe(CampaignStatus::Scheduled)
        ->and(($this->status)($canceled))->toBe(CampaignStatus::Canceled);
    Notification::assertSentToTimes($this->follower, CampaignMessage::class, 1);

    app(DispatchDueCampaigns::class)->handle(now());
    Notification::assertSentToTimes($this->follower, CampaignMessage::class, 1);
});

it('cancels a campaign an hour late rather than send it late', function (): void {
    Queue::fake();
    $late = ($this->campaign)(['send_at' => now()->subMinutes(61)]);
    $justDue = ($this->campaign)(['send_at' => now()->subMinutes(59)]);

    expect(app(DispatchDueCampaigns::class)->handle(now()))->toBe(['queued' => 1, 'missed' => 1])
        ->and(($this->status)($late))->toBe(CampaignStatus::Canceled);
    Queue::assertPushed(PushCampaign::class, fn (PushCampaign $job): bool => $job->campaignId === $justDue->id);
});

it('lets whoever runs the business cancel a scheduled campaign until it goes', function (): void {
    $scheduled = ($this->campaign)(['send_at' => now()->addDay()]);
    $due = ($this->campaign)(['send_at' => now()->subMinute()]);
    $sent = ($this->campaign)(['status' => CampaignStatus::Sent, 'sent_at' => now()->subDay(), 'send_at' => now()->subDay()]);

    ($this->as)($this->owner)->patch(route('campaigns.cancel', $scheduled))
        ->assertRedirect(route('campaigns.index'))
        ->assertInertiaFlash('toast.type', 'success');
    ($this->as)($this->owner)->patch(route('campaigns.cancel', $due))->assertInertiaFlash('toast.type', 'error');
    ($this->as)($this->owner)->patch(route('campaigns.cancel', $sent))->assertInertiaFlash('toast.type', 'error');
    // Already canceled, for a time still to come: once is enough.
    $canceled = ($this->campaign)(['send_at' => now()->addDay(), 'status' => CampaignStatus::Canceled]);
    ($this->as)($this->owner)->patch(route('campaigns.cancel', $canceled))->assertInertiaFlash('toast.type', 'error');

    expect([($this->status)($scheduled), ($this->status)($due), ($this->status)($sent)])
        ->toBe([CampaignStatus::Canceled, CampaignStatus::Scheduled, CampaignStatus::Sent]);
});

it('never lets staff, or another business, cancel a campaign', function (): void {
    $scheduled = ($this->campaign)(['send_at' => now()->addDay()]);
    $sisters = $this->context->bypass(fn (): Campaign => Campaign::factory()->for($this->tenants->a2)->create(['send_at' => now()->addDay()]));

    ($this->as)($this->tenants->member(User::factory()->create(), $this->tenants->a1))->patch(route('campaigns.cancel', $scheduled))->assertForbidden();
    ($this->as)($this->owner)->patch(route('campaigns.cancel', $sisters))->assertNotFound();

    expect([($this->status)($scheduled), ($this->status)($sisters)])->toBe([CampaignStatus::Scheduled, CampaignStatus::Scheduled]);
});
