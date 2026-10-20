<?php

use App\Actions\Account\AnonymiseInactiveAccounts;
use App\Actions\Account\WarnInactiveAccounts;
use App\Actions\Taps\ExpirePendingTaps;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prunes old rows of Prunable models, such as the tap log after its retention period (punchcard.taps.retention_days).
Schedule::command('model:prune')->daily();

// Marks signed-out taps nobody claimed in time as expired (punchcard.taps.pending_minutes).
Schedule::call(fn (): int => app(ExpirePendingTaps::class)->handle())->name('punchcard:expire-pending-taps')->everyFiveMinutes();

// The inactivity rule (CHW-58, docs/privacy/retention.md): email accounts a month before they reach 24 months unused,
// then delete or anonymise those warned long enough ago. Logs counts only, never who.
Schedule::call(function (): void {
    $warned = app(WarnInactiveAccounts::class)->handle(now());
    $gone = app(AnonymiseInactiveAccounts::class)->handle(now());
    Log::info('Inactivity rule ran.', ['warned' => $warned, 'gone' => $gone]);
})->name('punchcard:inactive-accounts')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
