<?php

declare(strict_types=1);

use Tests\Support\SunVectors as V;

/*
|--------------------------------------------------------------------------
| punchcard:nfc:check
|--------------------------------------------------------------------------
|
| Run after each deploy so a missing or malformed NFC key fails the deploy,
| not the first customer tap. It never prints key material.
|
*/

it('passes with a valid master key and meta key version', function (): void {
    $master = strtoupper(bin2hex(random_bytes(16)));
    config(['punchcard.nfc.sun_master_key' => $master, 'punchcard.nfc.key_version' => 3]);

    $this->artisan('punchcard:nfc:check')
        ->expectsOutputToContain('meta key version 3')
        ->doesntExpectOutputToContain($master)
        ->assertSuccessful();
});

it('fails when the configuration cannot derive keys', function (?string $master, mixed $version, string $reason): void {
    config(['punchcard.nfc.sun_master_key' => $master, 'punchcard.nfc.key_version' => $version]);

    $this->artisan('punchcard:nfc:check')
        ->expectsOutputToContain($reason)
        ->assertFailed();
})->with([
    'missing master key' => [null, 1, 'NFC_SUN_MASTER_KEY'],
    'malformed master key' => ['not-a-key', 1, 'NFC_SUN_MASTER_KEY'],
    'version not a whole number (config gives false)' => ['8F13D2A47C6E0B5591E8A3C4F0172B6E', false, 'NFC_SUN_KEY_VERSION'], // gitleaks:allow
    'version zero' => ['8F13D2A47C6E0B5591E8A3C4F0172B6E', 0, 'NFC_SUN_KEY_VERSION'], // gitleaks:allow
    'generated test meta key' => [V::META_KEY_HEX, 1, 'test key'],
    'generated test file key' => [V::FILE_KEY_HEX, 1, 'test key'],
    'RFC 4493 example key' => [V::RFC4493_KEY, 1, 'test key'],
    'all-zero test key' => [str_repeat('0', 32), 1, 'test key'],
    'AN10922 example key' => [V::AN10922_MASTER_KEY, 1, 'test key'],
]);
