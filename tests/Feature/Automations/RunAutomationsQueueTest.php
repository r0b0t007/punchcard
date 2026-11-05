<?php

declare(strict_types=1);

use App\Actions\Automations\RunAutomations;
use App\Actions\Customers\SummariseCustomer;
use App\Enums\AutomationTrigger;
use App\Features\Automations;
use App\Jobs\PushAutomationMessages;
use App\Models\Automation;
use App\Models\AutomationSend;
use App\Models\Follow;
use App\Models\User;
use App\Notifications\AutomationMessage;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The automation engine and its queue (CHW-46)
|--------------------------------------------------------------------------
|
| On the database queue: a business's claims and their jobs commit
| together, each job carrying the business's tenant, and a business whose
| jobs could not be queued claims nothing and stops no other.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-04 12:00:00');
    config(['queue.default' => 'database', 'webpush.vapid.public_key' => 'BAppPublicKey', 'webpush.vapid.private_key' => 'app-private-key']);
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);

    // A win-back on, and a follower going quiet, at each of A1 and A2.
    $this->customers = [];
    foreach ([$this->tenants->a1, $this->tenants->a2] as $business) {
        Feature::for($business)->activate(Automations::class);
        $this->context->bypass(fn (): Automation => Automation::factory()->create(['business_id' => $business->id, 'trigger' => AutomationTrigger::WinBack]));
        $customer = User::factory()->create();
        $enrollment = $this->tenants->enroll($customer, $this->tenants->cardA);
        $this->context->bypass(fn () => (new Follow)->forceFill(['organization_id' => $this->tenants->orgA->id, 'user_id' => $customer->id, 'notify' => true])->save());
        $this->tenants->stamp($enrollment, $business, ['created_at' => now()->subDays(20)]);
        app(SummariseCustomer::class)->handle($business->id, $business->organization_id, $customer->id);
        $customer->updatePushSubscription('https://fcm.googleapis.com/fcm/send/'.$customer->id, 'BKey', 'Secret');
        $this->customers[$business->id] = $customer;
    }

    $this->run = fn (): array => app(RunAutomations::class)->handle(CarbonImmutable::now());
    $this->claimedBy = fn (): array => $this->context->bypass(fn (): array => AutomationSend::query()->orderBy('business_id')->pluck('business_id')->all());
    $this->tenantsQueued = fn (): array => DB::table('jobs')->orderBy('id')->pluck('payload')
        ->map(fn (string $payload): mixed => json_decode($payload, true)['punchcard:tenant'] ?? null)->all();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('queues each business\'s messages in its own tenant', function (): void {
    ($this->run)();

    expect(($this->claimedBy)())->toBe([$this->tenants->a1->id, $this->tenants->a2->id])
        ->and(($this->tenantsQueued)())->toBe([
            ['organization' => $this->tenants->orgA->id, 'business' => $this->tenants->a1->id],
            ['organization' => $this->tenants->orgA->id, 'business' => $this->tenants->a2->id],
        ]);
});

it('claims nothing for a business whose messages could not be queued, and goes on with the others', function (): void {
    // A1's job is refused on its way into the queue.
    Event::listen(JobQueueing::class, function (JobQueueing $event): void {
        if ($event->job instanceof PushAutomationMessages && app(TenantContext::class)->businessId() === $this->tenants->a1->id) {
            throw new RuntimeException('The queue is down.');
        }
    });

    expect(($this->run)())->toBe(['businesses' => 1, 'claimed' => 1])
        ->and(($this->claimedBy)())->toBe([$this->tenants->a2->id])
        ->and(DB::table('jobs')->count())->toBe(1);

    // The next run claims and queues A1's.
    Event::forget(JobQueueing::class);
    ($this->run)();

    expect(($this->claimedBy)())->toBe([$this->tenants->a1->id, $this->tenants->a2->id]);
});

it('sends nothing for a business closed before its job ran, and the others\' as they come', function (): void {
    Notification::fake();
    ($this->run)();
    DB::table('businesses')->where('id', $this->tenants->a1->id)->update(['archived_at' => now()]);

    // The test process, not the worker, holds the memory: past the worker's default limit it would stop early (12).
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--memory' => 4096])->assertSuccessful();

    Notification::assertNothingSentTo($this->customers[$this->tenants->a1->id]);
    Notification::assertSentTo($this->customers[$this->tenants->a2->id], AutomationMessage::class);
});
