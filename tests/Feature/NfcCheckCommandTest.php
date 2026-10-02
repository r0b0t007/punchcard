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
    config(['punchcard.nfc.sun_master_key' => V::META_KEY_HEX, 'punchcard.nfc.key_version' => 3]);

    $this->artisan('punchcard:nfc:check')
        ->expectsOutputToContain('meta key version 3')
        ->doesntExpectOutputToContain(V::META_KEY_HEX)
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
    'version zero (e.g. "v2" cast to int)' => [V::META_KEY_HEX, 0, 'Key versions'],
    'all-zero test key' => [str_repeat('0', 32), 1, 'test key'],
    'AN10922 example key' => [V::AN10922_MASTER_KEY, 1, 'test key'],
]);
