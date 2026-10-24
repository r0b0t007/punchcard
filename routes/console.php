<?php

use App\Actions\Account\AnonymiseInactiveAccounts;
use App\Actions\Account\WarnInactiveAccounts;
use App\Actions\Taps\ExpirePendingTaps;
use App\Actions\Verification\DeleteExpiredDocuments;
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
// then delete or anonymise those whose warning's day has come. Two entries, so one failing never stops the other. Logs
// counts only, never who.
Schedule::call(fn () => Log::info('Inactivity warnings sent.', ['warned' => app(WarnInactiveAccounts::class)->handle(now())]))
    ->name('punchcard:inactive-accounts-warn')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
Schedule::call(fn () => Log::info('Inactive accounts swept.', ['gone' => app(AnonymiseInactiveAccounts::class)->handle(now())]))
    ->name('punchcard:inactive-accounts-sweep')->dailyAt('03:45')->withoutOverlapping()->onOneServer();

// Verification documents (CHW-145, docs/privacy/retention.md): deleted 3 months after the platform decides, or 12 months
// after they were sent while still waiting (CHW-58), and files with no row once a day old. Logs counts only.
Schedule::call(fn () => Log::info('Verification documents swept.', app(DeleteExpiredDocuments::class)->handle(now())))
    ->name('punchcard:documents-sweep')->dailyAt('04:00')->withoutOverlapping()->onOneServer();
