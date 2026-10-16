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
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('issues a token for the user and the current window, and reads it back', function (): void {
    $issued = MemberQrToken::issue($this->user);

    expect($issued['token'])->toStartWith('PC1.42.')
        ->and($issued['window'])->toBe(intdiv(now()->getTimestamp(), 30))
        ->and($issued['secondsLeft'])->toBe(25)
        ->and(MemberQrToken::read($issued['token']))->toBe(['userId' => 42, 'window' => $issued['window']]);
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

    expect(MemberQrToken::read($changed))->toBeNull();
})->with(['another user', 'another window', 'another mac', 'another version', 'cut short', 'zero-padded window', 'zero-padded user', 'overlong user', 'not a token']);

it('only reads tokens made with this app\'s key', function (): void {
    $token = MemberQrToken::issue($this->user)['token'];
    config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);

    expect(MemberQrToken::read($token))->toBeNull();
});
