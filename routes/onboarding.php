<?php

use App\Enums\OnboardingStep;
use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;

// The onboarding wizard (CHW-31): on the user's own business being set up (the `onboarding`
// middleware), never one named in the URL.
Route::middleware(['auth', 'verified', 'onboarding'])->prefix('onboarding')->name('onboarding.')->group(function (): void {
    Route::get('/', [OnboardingController::class, 'resume'])->name('show');
    Route::get('{step}', [OnboardingController::class, 'show'])
        ->whereIn('step', array_map(fn (OnboardingStep $step): string => $step->value, OnboardingStep::cases()))
        ->name('step');
    Route::post('business', [OnboardingController::class, 'saveBusiness'])->name('business');
    Route::put('location', [OnboardingController::class, 'saveLocation'])->name('location');
    Route::post('logo', [OnboardingController::class, 'saveLogo'])->name('logo');
    Route::post('logo/skip', [OnboardingController::class, 'skipLogo'])->name('logo.skip');
    Route::put('card', [OnboardingController::class, 'saveCard'])->name('card');
    Route::put('shipping', [OnboardingController::class, 'saveShipping'])->name('shipping');
    Route::post('cancel', [OnboardingController::class, 'cancel'])->name('cancel');
});
