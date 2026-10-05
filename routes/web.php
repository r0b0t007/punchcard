<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\TapController;
use App\Http\Middleware\NeverCache;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

// Component gallery for design QA, local development only (CHW-13).
if (app()->environment('local')) {
    Route::inertia('dev/components', 'dev/components')->name('dev.components');
}

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

// The NFC tap endpoint (CHW-25): the URL every stamper writes, and its result pages. Never cached.
Route::middleware(NeverCache::class)->group(function (): void {
    // block(): one tap request at a time per session, so two quick taps can't race on the session's
    // pending taps (TapSession) or on which result it shows.
    Route::get('t', [TapController::class, 'receive'])->middleware('throttle:tap')->block(10, 10)->name('taps.receive');
    Route::get('t/claim', [TapController::class, 'claim'])->middleware('auth')->block(10, 10)->name('taps.claim');
    Route::get('t/result', [TapController::class, 'show'])->block(10, 10)->name('taps.result');
    Route::inertia('t/busy', 'tap/refused', ['reason' => 'busy'])->name('taps.busy');
});

require __DIR__.'/settings.php';
