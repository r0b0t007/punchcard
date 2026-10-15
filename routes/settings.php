<?php

use App\Http\Controllers\ConfirmByEmailController;
use App\Http\Controllers\EmailChangeController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Middleware\NeverCache;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    // Deleting an account must not depend on having verified its email (CHW-139).
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    // From the delete dialog of an account without a password: confirm it's you, then back to the profile (CHW-23).
    Route::get('settings/profile/confirm', fn () => to_route('profile.edit'))->middleware(RequirePassword::class)->name('profile.confirm');

    // "Confirm it's you" by email, for an account without a password (CHW-23): never cached, the link is in the URL.
    Route::middleware(NeverCache::class)->group(function (): void {
        Route::post('user/confirm-by-email', [ConfirmByEmailController::class, 'store'])->middleware('throttle:confirm-link')->name('password.confirm-by-email');
        Route::get('user/confirm-by-email/{link}', [ConfirmByEmailController::class, 'show'])->whereNumber('link')->name('password.confirm-by-email.show');
        Route::post('user/confirm-by-email/{link}', [ConfirmByEmailController::class, 'confirm'])->whereNumber('link')->name('password.confirm-by-email.confirm');
    });
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});

// The link mailed to a new address asked for in settings (CHW-23), opened from that inbox, signed in or not.
Route::middleware(NeverCache::class)->group(function (): void {
    Route::get('email/confirm/{user}', [EmailChangeController::class, 'show'])->whereNumber('user')->name('email-change.show');
    Route::post('email/confirm/{user}', [EmailChangeController::class, 'confirm'])->whereNumber('user')->name('email-change.confirm');
});

Route::get('.well-known/passkey-endpoints', fn () => response()->json([
    'enroll' => route('security.edit'),
    'manage' => route('security.edit'),
]))->name('well-known.passkeys');
