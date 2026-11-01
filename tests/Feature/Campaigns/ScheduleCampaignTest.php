<?php

declare(strict_types=1);

use App\Actions\Campaigns\CreateCampaign;
use App\Actions\Campaigns\DeliverCampaign;
use App\Actions\Campaigns\DispatchDueCampaigns;
use App\Enums\BusinessRole;
use App\Enums\CampaignSegment;
use App\Enums\CampaignStatus;
use App\Http\Middleware\SetTenant;
use App\Jobs\PushCampaign;
use App\Models\Campaign;
use App\Models\Follow;
use App\Models\User;
use App\Notifications\CampaignMessage;
use App\Support\Tenancy\QueuedTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

it('takes a time from a minute ahead to the end of the 30th day, in the business\'s time', function (string $on, string $time, bool $allowed): void {
    $response = ($this->schedule)($on, $time);

    $allowed ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('send_time');
})->with([
    'earlier today, in the business\'s time' => ['2026-11-04', '14:00', false],
    'two minutes ahead' => ['2026-11-04', '14:04', true],
    'in 30 days' => ['2026-12-04', '14:00', true],
    'late on the 30th day' => ['2026-12-04', '23:30', true],
    'the 31st day' => ['2026-12-05', '00:00', false],
]);

it('says what is wrong with a day it can\'t read, never fails', function (array $input, string $error): void {
    ($this->as)($this->owner)->post(route('campaigns.store'), [
        'idempotency_key' => (string) Str::uuid(), 'segment' => 'all', 'title' => 'Slow Tuesday?', 'body' => 'Two stamps.', 'when' => 'later', ...$input,
    ])->assertSessionHasErrors($error);
})->with([
    'no day' => [['send_time' => '09:00'], 'send_on'],
    'a day the other way round' => [['send_on' => '05/11/2026', 'send_time' => '09:00'], 'send_on'],
    'no time' => [['send_on' => '2026-11-05'], 'send_time'],
]);

it('refuses a time a clock change skips', function (): void {
    $this->travelTo(Carbon::parse('2027-03-20 10:00:00'));
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Europe/Paris'])->save());

    // Paris goes from 02:00 to 03:00 on 28 March 2027: 02:30 is no time that day.
    ($this->schedule)('2027-03-28', '02:30')->assertSessionHasErrors(['send_time' => 'That time doesn’t exist that day: the clocks change.']);
    ($this->schedule)('2027-03-28', '03:30')->assertSessionHasNoErrors();
});

it('refuses a time outside the window whoever asks, not only the composer', function (): void {
    expect(fn () => app(CreateCampaign::class)->handle($this->tenants->a1, $this->owner, (string) Str::uuid(), CampaignSegment::All, 'Slow Tuesday?', 'Two stamps.', null, now()->addDays(40)))
        ->toThrow(LogicException::class, 'within 30 days');
});

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

it('queues a campaign once: never again a minute later, nor one sent now', function (): void {
    Queue::fake();
    $due = ($this->campaign)(['send_at' => now()->subMinute()]);
    ($this->as)($this->owner)->post(route('campaigns.store'), [
        'idempotency_key' => (string) Str::uuid(), 'segment' => 'all', 'title' => 'Now', 'body' => 'Two stamps.',
    ])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'On its way to 1 person.');

    app(DispatchDueCampaigns::class)->handle(now());
    app(DispatchDueCampaigns::class)->handle(now()->addMinute());

    // The one sent now, at once; the due one, once.
    Queue::assertPushed(PushCampaign::class, 2);
    Queue::assertPushed(PushCampaign::class, fn (PushCampaign $job): bool => $job->campaignId === $due->id);
});

it('queues nothing another scheduler queued between finding a campaign and claiming it', function (): void {
    Queue::fake();
    $due = ($this->campaign)(['send_at' => now()->subMinute()]);
    $raced = new stdClass;
    $raced->done = false;
    // Right after this pass reads the due campaigns, another one queues this campaign.
    DB::listen(function (QueryExecuted $query) use ($due, $raced): void {
        if (! $raced->done && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'queued_at')) {
            $raced->done = true;
            DB::table('campaigns')->where('id', $due->id)->update(['queued_at' => now()]);
        }
    });

    expect(app(DispatchDueCampaigns::class)->handle(now())['queued'])->toBe(0);
    Queue::assertNothingPushed();
});

it('never delivers a campaign more than an hour late, even from a job the queue held', function (): void {
    $held = ($this->campaign)(['send_at' => now()->subMinutes(61), 'queued_at' => now()->subMinutes(61)]);

    app(DeliverCampaign::class)->handle($held->id);

    Notification::assertNothingSent();
    expect(app(DispatchDueCampaigns::class)->handle(now())['missed'])->toBe(1)
        ->and(($this->status)($held))->toBe(CampaignStatus::Canceled);
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
