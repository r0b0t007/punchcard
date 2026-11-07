<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Which campaigns were sent before recipients were recorded (CHW-48, B10)
|--------------------------------------------------------------------------
|
| The migration marks them once: sent before the first send ever recorded,
| or any sent so far when none is recorded yet. One sent since whose pushes
| all failed has no sends either, yet recorded them.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-07 12:00:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->sent = fn (int $daysAgo): Campaign => $this->context->bypass(fn (): Campaign => Campaign::factory()->for($this->tenants->a1)->create([
        'status' => CampaignStatus::Sent, 'send_at' => now()->subDays($daysAgo), 'sent_at' => now()->subDays($daysAgo),
    ]));
    $this->migrate = function (): void {
        $migration = require database_path('migrations/2026_11_07_030026_add_records_sends_to_campaigns_table.php');
        $migration->down();
        $migration->up();
    };
    $this->recorded = fn (Campaign $campaign): bool => (bool) $this->context->bypass(fn (): bool => $campaign->refresh()->records_sends);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('marks only the campaigns sent before the first recorded send, never one since that reached nobody', function (): void {
    $before = ($this->sent)(10);
    $withSends = ($this->sent)(3);
    $nobodyReached = ($this->sent)(1);
    // Recording began with this one's sends, three days ago.
    Carbon::setTestNow(now()->subDays(3));
    $this->context->bypass(fn () => CampaignSend::factory()->create(['campaign_id' => $withSends->id]));
    Carbon::setTestNow('2026-11-07 12:00:00');

    ($this->migrate)();

    expect([($this->recorded)($before), ($this->recorded)($withSends), ($this->recorded)($nobodyReached)])->toBe([false, true, true]);
});

it('marks every campaign sent so far when no send is recorded yet, never one still to go', function (): void {
    $sent = ($this->sent)(2);
    $scheduled = $this->context->bypass(fn (): Campaign => Campaign::factory()->for($this->tenants->a1)->create(['send_at' => now()->addDay()]));

    ($this->migrate)();

    expect([($this->recorded)($sent), ($this->recorded)($scheduled)])->toBe([false, true]);
});
