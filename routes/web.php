<?php

use App\Http\Controllers\BusinessRegistrationController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MovedToBusinessController;
use App\Http\Controllers\MyCardsController;
use App\Http\Controllers\QrStandController;
use App\Http\Controllers\RewardController;
use App\Http\Controllers\StartController;
use App\Http\Controllers\TapController;
use App\Http\Middleware\NeverCache;
use App\Http\Middleware\RedirectToOnboarding;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::inertia('/', 'welcome')->name('home');

Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

// Component gallery for design QA, local development only (CHW-13).
if (app()->environment('local')) {
    Route::inertia('dev/components', 'dev/components')->name('dev.components');
}

// Business sign-up (CHW-31): the account and its business in one form; customers register through Fortify.
Route::middleware('guest')->group(function (): void {
    Route::get('business/register', [BusinessRegistrationController::class, 'create'])->name('business.register');
    Route::post('business/register', [BusinessRegistrationController::class, 'store'])->middleware('throttle:business-signup')->name('business.register.store');
});

// punchcard Pro, the business app (CHW-151): every page under /business, its manifest's scope, so a tap (/t) or a
// join link (/j) never opens in it.
Route::middleware(['auth', 'verified', 'tenant', 'pwa:business'])->prefix('business')->group(function (): void {
    // An owner still setting up goes to the onboarding wizard instead (CHW-31).
    Route::get('/', [DashboardController::class, 'show'])->middleware(RedirectToOnboarding::class)->name('dashboard');
    // The printable QR stand, its owner's (CHW-31); an owner still setting up finishes the wizard first.
    Route::middleware(RedirectToOnboarding::class)->group(function (): void {
        Route::get('qr-stand', [QrStandController::class, 'show'])->name('business.qr-stand');
        Route::post('qr-stand/printed', [QrStandController::class, 'printed'])->name('business.qr-stand.printed');
        // The cards and the card builder's saves (CHW-32); {card} resolves in the tenant.
        Route::get('cards', [CardController::class, 'index'])->name('cards.index');
        Route::get('cards/create', [CardController::class, 'create'])->name('cards.create');
        Route::get('cards/{card}/edit', [CardController::class, 'edit'])->whereNumber('card')->name('cards.edit');
        Route::post('cards', [CardController::class, 'store'])->name('cards.store');
        Route::put('cards/{card}', [CardController::class, 'update'])->whereNumber('card')->name('cards.update');
        Route::patch('cards/{card}/status', [CardController::class, 'status'])->whereNumber('card')->name('cards.status');
        Route::post('cards/{card}/banner', [CardController::class, 'banner'])->whereNumber('card')->name('cards.banner');
    });
});

// The join page (CHW-31): the QR stand's link, public; adding the card takes a signed-in customer. Never cached.
Route::middleware([NeverCache::class, 'pwa:customer'])->group(function (): void {
    Route::get('j/{slug}', [JoinController::class, 'show'])->middleware('throttle:join-page')->name('join.show');
    Route::middleware(['guest', 'throttle:join-page'])->group(function (): void {
        Route::get('j/{slug}/register', [JoinController::class, 'register'])->name('join.register');
        Route::get('j/{slug}/login', [JoinController::class, 'login'])->name('join.login');
    });
    Route::post('j/{slug}', [JoinController::class, 'store'])->middleware(['auth', 'throttle:join'])->name('join.store');
});

// The NFC tap endpoint (CHW-25): the URL every stamper writes, and its result pages. Never cached.
Route::middleware([NeverCache::class, 'pwa:customer'])->group(function (): void {
    // block(): one tap request at a time per session, so two quick taps can't race on the session's
    // pending taps (TapSession) or on which result it shows.
    Route::get('t', [TapController::class, 'receive'])->middleware('throttle:tap')->block(10, 10)->name('taps.receive');
    Route::get('t/claim', [TapController::class, 'claim'])->middleware('auth')->block(10, 10)->name('taps.claim');
    Route::get('t/result', [TapController::class, 'show'])->block(10, 10)->name('taps.result');
    Route::inertia('t/busy', 'tap/refused', ['reason' => 'busy'])->name('taps.busy');
});

// The customer's cards (CHW-27), rewards and redeeming them (CHW-26). Not "verified": an unverified customer sees
// their rewards and is asked to verify before redeeming (OpenRedeemWindow). Never cached. The redeem
// screen polls while the tap lands in the same session (an Android PWA shares the browser's
// cookies): block() queues them like the /t requests, so a poll never writes back a stale session.
Route::middleware(['auth', NeverCache::class, 'pwa:customer'])->group(function (): void {
    // Home after signing in (CHW-27): the dashboard, the admin panel or My cards.
    Route::get('start', StartController::class)->name('start');
    // My cards (CHW-27): the customer's home, and the cards of anyone who collects stamps.
    Route::get('app', [MyCardsController::class, 'index'])->name('app.cards');
    Route::get('app/cards/{enrollment}', [MyCardsController::class, 'show'])->whereNumber('enrollment')->name('app.cards.show');
    Route::patch('app/cards/{enrollment}/follow', [MyCardsController::class, 'follow'])->whereNumber('enrollment')->name('app.cards.follow');
    Route::get('rewards', [RewardController::class, 'index'])->name('rewards.index');
    Route::get('rewards/{reward}/redeem', [RewardController::class, 'show'])->whereNumber('reward')->middleware('throttle:reward-screen')->block(10, 10)->name('rewards.redeem.show');
    Route::post('rewards/{reward}/redeem', [RewardController::class, 'redeem'])->whereNumber('reward')->middleware('throttle:reward-window')->block(10, 10)->name('rewards.redeem');
    Route::delete('rewards/{reward}/redeem', [RewardController::class, 'close'])->whereNumber('reward')->middleware('throttle:reward-window')->block(10, 10)->name('rewards.redeem.close');
});

// The business app's addresses before it moved under /business (CHW-151), for good: pages, and the saves of a page
// loaded before the move, with their query string, method and form.
Route::any('{path}', MovedToBusinessController::class)
    ->where('path', 'dashboard|cards(?:/.*)?|onboarding(?:/.*)?')
    ->name('business.moved');

// punchcard Pro's sign-in, inside its scope: opening the app signed out never leaves it. The form posts to Fortify's
// /login, without a page load.
Route::get('business/login', [AuthenticatedSessionController::class, 'create'])
    ->middleware(['guest', 'pwa:business'])
    ->name('business.login');

// The offline My cards page (CHW-35): the shell the service worker precaches and serves when a page of My cards
// can't load. The same for everyone: the cards come from the phone.
Route::view('offline', 'offline')->name('offline');

require __DIR__.'/onboarding.php';
require __DIR__.'/settings.php';
