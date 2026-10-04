<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prunes old rows of Prunable models, such as the tap log after its retention period (punchcard.taps.retention_days).
Schedule::command('model:prune')->daily();
