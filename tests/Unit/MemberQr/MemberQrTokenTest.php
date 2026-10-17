<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\MemberQr\MemberQrToken;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The member QR's token (CHW-28, C10)
|--------------------------------------------------------------------------
|
| PC1.{user}.{window}.{mac}: who the customer is and which 30-second window
| it was shown in, signed with a key of the app's own (HMAC). Anyone can
| read it; nobody can change it or make one.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-16 10:00:05');
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'punchcard.member_qr.window_seconds' => 30]);
    $this->user = (new User)->forceFill(['id' => 42]);
    // As MemberQrScan checks one: its parts, then its signature.
    $this->read = function (string $token): ?array {
        $fields = MemberQrToken::fields($token);

        return $fields !== null && MemberQrToken::authentic($fields) ? ['userId' => $fields['userId'], 'window' => $fields['window']] : null;
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('issues a token for the user and the current window, and reads it back', function (): void {
    $issued = MemberQrToken::issue($this->user);

    expect($issued['token'])->toStartWith('PC1.42.')
        ->and($issued['window'])->toBe(intdiv(now()->getTimestamp(), 30))
        ->and($issued['secondsLeft'])->toBe(25)
        ->and(($this->read)($issued['token']))->toBe(['userId' => 42, 'window' => $issued['window']]);
});

it('changes the token every window', function (): void {
    $first = MemberQrToken::issue($this->user)['token'];
    $this->travel(30)->seconds();

    expect(MemberQrToken::issue($this->user)['token'])->not->toBe($first);
});

it('refuses a token changed in any part, or not a token at all', function (string $how): void {
    $token = MemberQrToken::issue($this->user)['token'];
    [$version, $user, $window, $mac] = explode('.', $token);

    $changed = match ($how) {
        'another user' => "{$version}.43.{$window}.{$mac}",
        'another window' => "{$version}.{$user}.".($window + 1).".{$mac}",
        'another mac' => "{$version}.{$user}.{$window}.".strrev($mac),
        'another version' => "PC2.{$user}.{$window}.{$mac}",
        'cut short' => substr($token, 0, -1),
        // Another spelling of the same window or user: never a second token.
        'zero-padded window' => "{$version}.{$user}.0{$window}.{$mac}",
        'zero-padded user' => "{$version}.0{$user}.{$window}.{$mac}",
        'overlong user' => "{$version}.9999999999999999999.{$window}.{$mac}",
        'not a token' => 'https://example.test/',
    };

    expect(($this->read)($changed))->toBeNull();
})->with(['another user', 'another window', 'another mac', 'another version', 'cut short', 'zero-padded window', 'zero-padded user', 'overlong user', 'not a token']);

it('only reads tokens made with this app\'s key', function (): void {
    $token = MemberQrToken::issue($this->user)['token'];
    config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);

    expect(($this->read)($token))->toBeNull();
});

it('gives a short code to type: the member number and six digits that change every window', function (): void {
    $code = MemberQrToken::shortCode($this->user);

    expect($code)->toMatch('/^42 [0-9]{6}$/')
        ->and(MemberQrToken::codeFields($code))->toBe(['userId' => 42, 'digits' => substr($code, 3)]);

    $this->travel(30)->seconds();
    expect(MemberQrToken::shortCode($this->user))->not->toBe($code);
});

it('reads a short code however it was typed, and nothing else', function (string $typed, ?array $fields): void {
    expect(MemberQrToken::codeFields($typed))->toBe($fields);
})->with([
    'with a space' => ['42 123456', ['userId' => 42, 'digits' => '123456']],
    'run together' => ['42123456', ['userId' => 42, 'digits' => '123456']],
    'with a dash and spaces around' => [' 42-123 456 ', ['userId' => 42, 'digits' => '123456']],
    'with the middle dot shown' => ['42 · 123 456', ['userId' => 42, 'digits' => '123456']],
    'too few digits' => ['42 12345', null],
    'a zero-padded member' => ['042 123456', null],
    'letters' => ['42 12345a', null],
    'a token' => ['PC1.42.1.abc', null],
]);

it('only gives this app\'s short codes', function (): void {
    $code = MemberQrToken::shortCode($this->user);
    config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);

    expect(MemberQrToken::shortCode($this->user))->not->toBe($code);
});

it('reads a short code typed with Arabic-Indic digits, and refuses a long input', function (): void {
    expect(MemberQrToken::codeFields('٤٢ ١٢٣٤٥٦'))->toBe(['userId' => 42, 'digits' => '123456'])
        ->and(MemberQrToken::codeFields('۴۲ ۱۲۳۴۵۶'))->toBe(['userId' => 42, 'digits' => '123456'])
        ->and(MemberQrToken::codeFields('42 '.str_repeat(' ', 100).'123456'))->toBeNull();
});
