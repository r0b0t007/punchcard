<?php

use App\Http\Controllers\BusinessRegistrationController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\CounterController;
use App\Http\Controllers\CounterScanController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FinishSignUpController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LoginLinkController;
use App\Http\Controllers\MemberQrController;
use App\Http\Controllers\MovedToBusinessController;
use App\Http\Controllers\MyCardsController;
use App\Http\Controllers\OfflinePageController;
use App\Http\Controllers\OpenBusinessController;
use App\Http\Controllers\QrStandController;
use App\Http\Controllers\RewardController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffInvitationController;
use App\Http\Controllers\StamperController;
use App\Http\Controllers\StartController;
use App\Http\Controllers\TapController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\VerificationController;
use App\Http\Middleware\NeverCache;
use App\Http\Middleware\RedirectToOnboarding;
use App\Support\Auth\MailedLink;
use Illuminate\Routing\Middleware\ValidateSignature;
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

// Signing in with an email link, customers only (CHW-23): the address, the link's page (one button: opening it spends
// nothing), then a new customer's first details. Never cached: the link is in the URL.
Route::middleware(['guest', NeverCache::class])->group(function (): void {
    Route::get('login/email', [LoginLinkController::class, 'create'])->name('login-link.create');
    Route::post('login/email', [LoginLinkController::class, 'store'])->middleware('throttle:login-link')->name('login-link.store');
    Route::get('login/link/{link}', [LoginLinkController::class, 'show'])->whereNumber('link')->name('login-link.show');
    Route::post('login/link/{link}', [LoginLinkController::class, 'signIn'])->whereNumber('link')->name('login-link.sign-in');
    Route::get('sign-up/finish', [FinishSignUpController::class, 'create'])->name('sign-up.finish');
    Route::post('sign-up/finish', [FinishSignUpController::class, 'store'])->name('sign-up.finish.store');
});

// punchcard Pro, the business app (CHW-151): every page under /business, its manifest's scope, so a tap (/t) or a
// join link (/j) never opens in it.
Route::middleware(['auth', 'verified', 'tenant', 'pwa:business'])->prefix('business')->group(function (): void {
    // An owner still setting up goes to the onboarding wizard instead (CHW-31).
    Route::get('/', [DashboardController::class, 'show'])->middleware(RedirectToOnboarding::class)->name('dashboard');
    // A business's page from its emails (CHW-295): selects the business the signed link names, then opens the page.
    Route::get('open/{business}/{page}', OpenBusinessController::class)->whereNumber('business')
        ->whereIn('page', array_keys(MailedLink::BUSINESS_PAGES))->middleware(ValidateSignature::relative())->name('business.open');
    // The tenant switcher (CHW-147): work in another business or network, then its dashboard.
    Route::put('tenant', [TenantController::class, 'update'])->name('tenant.update');
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
        // Counter mode (CHW-29, S1): whoever works in the business; {stamper} resolves in the tenant.
        Route::get('counter', [CounterController::class, 'show'])->name('counter.show');
        Route::post('counter/stampers/{stamper}/arm', [CounterController::class, 'arm'])->whereNumber('stamper')->middleware('throttle:counter-arm')->name('counter.arm');
        Route::delete('counter/stampers/{stamper}/arm', [CounterController::class, 'disarm'])->whereNumber('stamper')->name('counter.disarm');
        // Scanning a customer's member QR (CHW-30, S2, S3): the staff member's own scans; {scan} resolves in the tenant.
        Route::get('counter/scan', [CounterScanController::class, 'create'])->name('counter.scan');
        Route::post('counter/scans', [CounterScanController::class, 'store'])->middleware('throttle:counter-scan')->name('counter.scans.store');
        Route::get('counter/scans/{scan}', [CounterScanController::class, 'show'])->whereUlid('scan')->name('counter.scans.show');
        Route::post('counter/scans/{scan}/stamps', [CounterScanController::class, 'stamp'])->whereUlid('scan')->middleware('throttle:counter-arm')->name('counter.scans.stamp');
        Route::post('counter/scans/{scan}/rewards/{reward}/redeem', [CounterScanController::class, 'redeem'])->whereUlid('scan')->where('reward', '[0-9]{1,18}')->middleware('throttle:counter-arm')->name('counter.scans.redeem');
        // The stampers and the taps refused (CHW-36, B12), the owner's; {stamper} resolves in the tenant.
        Route::get('stampers', [StamperController::class, 'index'])->name('stampers.index');
        Route::patch('stampers/{stamper}/status', [StamperController::class, 'status'])->whereNumber('stamper')->middleware('throttle:stamper-status')->name('stampers.status');
        // The staff and their invitations (CHW-146), the owner's; {member} is a business_user row.
        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('staff/invitations', [StaffController::class, 'invite'])->middleware('throttle:staff-invite')->name('staff.invitations.store');
        Route::delete('staff/invitations/{invitation}', [StaffController::class, 'cancelInvitation'])->whereNumber('invitation')->name('staff.invitations.destroy');
        Route::delete('staff/{member}', [StaffController::class, 'destroy'])->whereNumber('member')->name('staff.destroy');
        // Verify your business (CHW-145): its documents, sent by whoever runs it, never shown back.
        Route::get('verification', [VerificationController::class, 'show'])->name('verification.show');
        Route::post('verification/{kind}', [VerificationController::class, 'store'])->middleware('throttle:verification-upload')->name('verification.store');
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

// An invitation to a business's staff (CHW-146), opened from the invited inbox, signed in or not. Never cached.
Route::middleware(NeverCache::class)->group(function (): void {
    Route::get('staff-invitations/{invitation}', [StaffInvitationController::class, 'show'])->whereNumber('invitation')->name('staff-invitations.show');
    Route::post('staff-invitations/{invitation}', [StaffInvitationController::class, 'accept'])->whereNumber('invitation')->name('staff-invitations.accept');
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

// A verification document for the platform admin (CHW-145): the 5-minute signed link from its business's page in A1,
// never cached; who may is BusinessDocumentPolicy::open.
Route::get('admin-documents/{document}', [DocumentController::class, 'show'])->whereNumber('document')
    ->middleware(['auth', 'signed', NeverCache::class])->name('documents.view');

// The customer's cards (CHW-27), rewards and redeeming them (CHW-26). Not "verified": an unverified customer sees
// their rewards and is asked to verify before redeeming (OpenRedeemWindow). Never cached. The redeem
// screen polls while the tap lands in the same session (an Android PWA shares the browser's
// cookies): block() queues them like the /t requests, so a poll never writes back a stale session.
Route::middleware(['auth', NeverCache::class, 'pwa:customer'])->group(function (): void {
    // Home after signing in (CHW-27): the dashboard, the admin panel or My cards.
    Route::get('start', StartController::class)->name('start');
    // Back to admin, from an owner's business app (CHW-144).
    Route::post('impersonation/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');
    // My cards (CHW-27): the customer's home, and the cards of anyone who collects stamps.
    Route::get('app', [MyCardsController::class, 'index'])->name('app.cards');
    // "My QR" (CHW-28, C10): the rotating member QR staff scan when the phone has no NFC.
    Route::get('app/qr', [MemberQrController::class, 'show'])->name('app.qr');
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
// can't load. The same for everyone: the cards come from the phone, so no session (nor its cookie) in the copy kept.
Route::get('offline', OfflinePageController::class)
    ->withoutMiddleware('web')
    ->name('offline');

require __DIR__.'/onboarding.php';
require __DIR__.'/settings.php';
