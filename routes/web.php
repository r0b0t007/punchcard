<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\TapController;
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
Route::middleware('cache.headers:no_store;private')->group(function (): void {
    Route::get('t', [TapController::class, 'receive'])->middleware('throttle:tap')->name('taps.receive');
    Route::get('t/claim', [TapController::class, 'claim'])->middleware('auth')->name('taps.claim');
    Route::get('t/result', [TapController::class, 'show'])->name('taps.result');
});

require __DIR__.'/settings.php';
