<?php

use App\Actions\Taps\ExpirePendingTaps;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prunes old rows of Prunable models, such as the tap log after its retention period (punchcard.taps.retention_days).
Schedule::command('model:prune')->daily();

// Marks signed-out taps nobody claimed in time as expired (punchcard.taps.pending_minutes).
Schedule::call(fn (): int => app(ExpirePendingTaps::class)->handle())->name('punchcard:expire-pending-taps')->everyFiveMinutes();
