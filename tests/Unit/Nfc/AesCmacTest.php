<?php

declare(strict_types=1);

use App\Support\Nfc\AesCmac;
use Tests\Support\SunVectors;

/*
|--------------------------------------------------------------------------
| AES-CMAC (RFC 4493)
|--------------------------------------------------------------------------
|
| The four examples from RFC 4493 section 4 cover the empty message, one
| complete block, a partial last block and several complete blocks.
|
*/

it('matches the RFC 4493 examples', function (int $length, string $expected): void {
    $message = (string) hex2bin(substr(SunVectors::RFC4493_MESSAGE, 0, $length * 2));

    expect(bin2hex(AesCmac::compute((string) hex2bin(SunVectors::RFC4493_KEY), $message)))->toBe($expected);
})->with([
    'empty message' => [0, 'bb1d6929e95937287fa37d129b756746'],
    'one block' => [16, '070a16b46b4d4144f79bdd9dd04a287c'],
    'partial last block' => [40, 'dfa66747de9ae63030ca32611497c827'],
    'four blocks' => [64, '51f0bebf7e3b9d92fc49741779363cfe'],
]);

it('rejects a key that is not 16 bytes', function (): void {
    AesCmac::compute(str_repeat("\0", 15), '');
})->throws(InvalidArgumentException::class);
