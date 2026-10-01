<?php

use App\Http\Controllers\LocaleController;
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

require __DIR__.'/settings.php';
