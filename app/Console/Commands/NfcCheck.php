<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Nfc\KeyDiversifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Post-deploy check that NFC_SUN_MASTER_KEY and NFC_SUN_KEY_VERSION can derive
 * tag keys. Without it a bad value only shows up as a 500 on the first tap.
 * Never prints key material.
 */
#[Signature('punchcard:nfc:check')]
#[Description('Check that the NFC master key and meta key version can derive stamper keys')]
class NfcCheck extends Command
{
    /** Trivial, published or committed (tests/Support/SunVectors.php) keys that must never protect real tags. */
    private const array TEST_KEYS = [
        '00000000000000000000000000000000', // gitleaks:allow
        '00112233445566778899AABBCCDDEEFF', // NXP AN10922 example, gitleaks:allow
        '2B7E151628AED2A6ABF7158809CF4F3C', // RFC 4493 example, gitleaks:allow
        '8F13D2A47C6E0B5591E8A3C4F0172B6D', // SunVectors::META_KEY_HEX, gitleaks:allow
        '3E9A51C7D20F84B6A1E3576C9D08F42B', // SunVectors::FILE_KEY_HEX, gitleaks:allow
    ];

    public function handle(): int
    {
        $masterKey = config('punchcard.nfc.sun_master_key');
        $version = config('punchcard.nfc.key_version');

        if (is_string($masterKey) && in_array(strtoupper($masterKey), self::TEST_KEYS, true)) {
            $this->error('NFC_SUN_MASTER_KEY is a published test key. Generate a new one (docs/runbooks/stamper-keys.md).');

            return self::FAILURE;
        }

        if (! is_int($version) || $version < 1 || $version > KeyDiversifier::MAX_KEY_VERSION) {
            $this->error('NFC_SUN_KEY_VERSION must be a whole number from 1 to 65535.');

            return self::FAILURE;
        }

        try {
            app(KeyDiversifier::class)->metaReadKey($version);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('NFC keys OK: master key valid, meta key version '.$version.'.');

        return self::SUCCESS;
    }
}
