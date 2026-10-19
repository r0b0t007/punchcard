<?php

use App\Http\Controllers\ConfirmByEmailController;
use App\Http\Controllers\EmailChangeController;
use App\Http\Controllers\PasskeyOfferController;
use App\Http\Controllers\Settings\PrivacyController;
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
    // Privacy (CHW-58, C12): punchcard's news on or off, verified or not (turning it off never needs a confirmed email).
    Route::get('settings/privacy', [PrivacyController::class, 'edit'])->name('privacy.edit');
    Route::patch('settings/privacy/marketing', [PrivacyController::class, 'updateMarketing'])->middleware('throttle:30,1')->name('privacy.marketing');
    // A place followed or left from Privacy (the same Follow as on the card's page): {enrollment} is one of their own cards.
    Route::patch('settings/privacy/follows/{enrollment}', [PrivacyController::class, 'follow'])->whereNumber('enrollment')->middleware('throttle:30,1')->name('privacy.follow');
    // Download my data (CHW-58): confirm it's you (15 minutes) from the page, then back to it; the file itself never cached, 5 an hour.
    // A POST page load: browsers never preload one, so the service worker leaves it to the network and the file saves (WebKit).
    Route::get('settings/privacy/confirm', fn () => to_route('privacy.edit'))->middleware(RequirePassword::using(null, PrivacyController::EXPORT_CONFIRM_SECONDS))->name('privacy.confirm');
    Route::post('settings/privacy/export', [PrivacyController::class, 'export'])
        ->middleware([NeverCache::class, 'throttle:data-export'])
        ->name('privacy.export');
    // "Not now" on the passkey offered after an email-link sign-in (CHW-23).
    Route::post('passkey-offer/dismiss', [PasskeyOfferController::class, 'dismiss'])->name('passkey-offer.dismiss');
    // A new address dropped before its link is opened (CHW-23).
    Route::delete('settings/email-change', [EmailChangeController::class, 'destroy'])->name('email-change.cancel');

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
