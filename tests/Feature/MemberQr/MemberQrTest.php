<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Actions\MemberQr\MemberQrRefusal;
use App\Actions\MemberQr\MemberQrRefused;
use App\Actions\MemberQr\MemberQrScan;
use App\Actions\MemberQr\VerifyMemberQr;
use App\Models\User;
use App\Support\MemberQr\MemberQrToken;
use App\Support\QrCode;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The member QR (CHW-28, C10)
|--------------------------------------------------------------------------
|
| A customer whose phone has no NFC shows "My QR": a code that changes every
| window, which staff scan (CHW-30). The scan is verified here: a real token,
| from the current or previous window, of a customer who still exists, used
| once per card (scope) while it lasts.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-16 10:00:05');
    app()->setLocale('en');
    $this->customer = User::factory()->create(['name' => 'Salma Tazi']);
    $this->verify = fn (string $token, string $scope = 'card:1') => app(VerifyMemberQr::class)->handle($token, $scope);
    $this->refusalOf = function (string $token, string $scope = 'card:1'): ?MemberQrRefusal {
        try {
            ($this->verify)($token, $scope);
        } catch (MemberQrRefused $refused) {
            return $refused->reason;
        }

        return null;
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('verifies a customer\'s token: who they are and when it was shown', function (): void {
    $token = MemberQrToken::issue($this->customer)['token'];

    $scan = ($this->verify)($token);

    expect($scan->userId)->toBe($this->customer->id)
        ->and($scan->window)->toBe(MemberQrToken::issue($this->customer)['window'])
        ->and($scan->scannedAt->equalTo(now()))->toBeTrue();
});

it('accepts the previous window\'s code only in the next one\'s first seconds, never an older or a later one', function (): void {
    // Shown at 10:00:05, in the window from 10:00:00; the next starts at 10:00:30.
    $token = MemberQrToken::issue($this->customer)['token'];

    $this->travelTo(Carbon::parse('2026-10-16 10:00:30')->addSeconds(MemberQrScan::GRACE_SECONDS - 1));
    expect(($this->refusalOf)($token, 'card:previous'))->toBeNull();

    $this->travelTo(Carbon::parse('2026-10-16 10:00:30')->addSeconds(MemberQrScan::GRACE_SECONDS));
    expect(($this->refusalOf)($token, 'card:late'))->toBe(MemberQrRefusal::Expired);

    $later = MemberQrToken::issue($this->customer, now()->addSeconds(30))['token'];
    expect(($this->refusalOf)($later))->toBe(MemberQrRefusal::Expired);
});

it('uses one customer\'s code once per card while any code of theirs counts, and again for another card', function (): void {
    $token = MemberQrToken::issue($this->customer)['token'];

    expect(($this->refusalOf)($token, 'card:1'))->toBeNull()
        ->and(($this->refusalOf)($token, 'card:1'))->toBe(MemberQrRefusal::Replayed)
        ->and(($this->refusalOf)($token, 'card:2'))->toBeNull();

    // The next code, shown as the first was scanned: still the same visit for that card.
    $this->travel(30)->seconds();
    expect(($this->refusalOf)(MemberQrToken::issue($this->customer)['token'], 'card:1'))->toBe(MemberQrRefusal::Replayed);

    // A visit later, the card takes their code again.
    $this->travel(60)->seconds();
    expect(($this->refusalOf)(MemberQrToken::issue($this->customer)['token'], 'card:1'))->toBeNull();
});

it('says why a token is refused', function (): void {
    $token = MemberQrToken::issue($this->customer)['token'];

    expect(($this->refusalOf)('hello'))->toBe(MemberQrRefusal::Malformed)
        ->and(($this->refusalOf)(substr($token, 0, -2).'xx'))->toBe(MemberQrRefusal::Forged);
});

it('refuses the token of an account deleted since, gone or kept for its stamps', function (string $how): void {
    $token = MemberQrToken::issue($this->customer)['token'];

    match ($how) {
        'deleted' => app(DeleteAccount::class)->handle($this->customer),
        // Anonymised, as DeleteAccount does for an account whose stamps stay in the ledger.
        'anonymised' => $this->customer->forceFill(['anonymised_at' => now()])->save(),
    };

    expect(($this->refusalOf)($token))->toBe(MemberQrRefusal::UnknownUser);
})->with(['deleted', 'anonymised']);

it('shows a signed-in customer their own code, never cached, and a new one each window', function (): void {
    $issued = MemberQrToken::issue($this->customer);

    $this->actingAs($this->customer)->get(route('app.qr'))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page): Assert => $page->component('app/qr')
            ->where('qrCode', QrCode::dataUrl($issued['token']))
            ->where('secondsLeft', 25)
            ->where('windowSeconds', 30)
            ->where('issuedAt', now()->getTimestampMs())
            ->where('firstName', 'Salma'));

    $this->travel(30)->seconds();
    $this->actingAs($this->customer)->get(route('app.qr'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('qrCode', QrCode::dataUrl(MemberQrToken::issue($this->customer)['token'])));
});

it('asks a guest to sign in first', function (): void {
    $this->get(route('app.qr'))->assertRedirect(route('login'));
});

it('still refuses the next code for the same card when the first was scanned as its window ended', function (): void {
    // Scanned at 10:00:29, the last second of its window; the next code shows from 10:00:30.
    $this->travelTo(Carbon::parse('2026-10-16 10:00:29'));
    expect(($this->refusalOf)(MemberQrToken::issue($this->customer)['token'], 'card:1'))->toBeNull();

    $this->travelTo(Carbon::parse('2026-10-16 10:00:46'));
    expect(($this->refusalOf)(MemberQrToken::issue($this->customer)['token'], 'card:1'))->toBe(MemberQrRefusal::Replayed);
});

it('makes a scan only out of a checked token or short code: no other way to one', function (): void {
    $class = new ReflectionClass(MemberQrScan::class);
    $makers = array_filter(
        $class->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $method): bool => $method->getReturnType() instanceof ReflectionNamedType
            && in_array($method->getReturnType()->getName(), ['self', 'static', MemberQrScan::class], true),
    );

    expect($class->getConstructor()?->isPrivate())->toBeTrue()
        ->and(collect($makers)->map(fn (ReflectionMethod $method): string => $method->getName())->sort()->values()->all())->toBe(['fromCode', 'fromToken']);
});

it('verifies the short code shown under the QR, in its window or the next one\'s first seconds', function (): void {
    // Shown at 10:00:05, in the window from 10:00:00.
    $code = MemberQrToken::shortCode($this->customer);

    expect(($this->verify)($code)->userId)->toBe($this->customer->id);

    $this->travelTo(Carbon::parse('2026-10-16 10:00:30')->addSeconds(MemberQrScan::GRACE_SECONDS - 1));
    expect(($this->refusalOf)($code, 'card:previous'))->toBeNull();

    $this->travelTo(Carbon::parse('2026-10-16 10:00:30')->addSeconds(MemberQrScan::GRACE_SECONDS));
    expect(($this->refusalOf)($code, 'card:late'))->toBe(MemberQrRefusal::Forged);
});

it('uses a customer once per scope whether staff scan the QR or type the short code', function (): void {
    expect(($this->refusalOf)(MemberQrToken::issue($this->customer)['token'], 'card:1'))->toBeNull()
        ->and(($this->refusalOf)(MemberQrToken::shortCode($this->customer), 'card:1'))->toBe(MemberQrRefusal::Replayed);
});

it('says why a short code is refused', function (string $how, MemberQrRefusal $reason): void {
    $code = MemberQrToken::shortCode($this->customer);
    [$member, $digits] = explode(' ', $code);

    $typed = match ($how) {
        'not a code' => 'hello',
        'wrong digits' => $member.' '.str_pad((string) (((int) $digits + 1) % 1_000_000), 6, '0', STR_PAD_LEFT),
        'another member' => ($member + 1).' '.$digits,
    };

    expect(($this->refusalOf)($typed))->toBe($reason);
})->with([
    'not a code' => ['not a code', MemberQrRefusal::Malformed],
    'wrong digits' => ['wrong digits', MemberQrRefusal::Forged],
    'another member' => ['another member', MemberQrRefusal::Forged],
]);

it('refuses a member\'s short codes for a while after too many wrong ones, wherever typed, never their QR', function (): void {
    $code = MemberQrToken::shortCode($this->customer);
    [$member, $digits] = explode(' ', $code);
    $wrong = $member.' '.str_pad((string) (((int) $digits + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
    $someoneElse = User::factory()->create();

    foreach (range(1, MemberQrScan::WRONG_CODES - 1) as $attempt) {
        expect(($this->refusalOf)($wrong, 'scan:business:'.$attempt))->toBe(MemberQrRefusal::Forged);
    }

    expect(($this->refusalOf)($code, 'card:before'))->toBeNull()
        ->and(($this->refusalOf)($wrong, 'scan:business:last'))->toBe(MemberQrRefusal::Forged)
        ->and(($this->refusalOf)($code, 'card:after'))->toBe(MemberQrRefusal::TooManyAttempts)
        ->and(($this->refusalOf)(MemberQrToken::issue($this->customer)['token'], 'card:qr'))->toBeNull()
        ->and(($this->refusalOf)(MemberQrToken::shortCode($someoneElse), 'card:other'))->toBeNull();

    $this->travel(MemberQrScan::WRONG_CODES_SECONDS)->seconds();
    expect(($this->refusalOf)(MemberQrToken::shortCode($this->customer), 'card:later'))->toBeNull();
});
