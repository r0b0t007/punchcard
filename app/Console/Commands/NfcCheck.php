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
    /** Published or trivial keys that must never protect real tags. */
    private const array TEST_KEYS = [
        '00000000000000000000000000000000', // gitleaks:allow
        '00112233445566778899AABBCCDDEEFF', // NXP AN10922 example, gitleaks:allow
    ];

    public function handle(): int
    {
        $masterKey = config('punchcard.nfc.sun_master_key');
        $version = config('punchcard.nfc.key_version');

        if (is_string($masterKey) && in_array(strtoupper($masterKey), self::TEST_KEYS, true)) {
            $this->error('NFC_SUN_MASTER_KEY is a published test key. Generate a new one (docs/runbooks/stamper-keys.md).');

            return self::FAILURE;
        }

        try {
            app(KeyDiversifier::class)->metaReadKey(is_int($version) ? $version : 0);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('NFC keys OK: master key valid, meta key version '.$version.'.');

        return self::SUCCESS;
    }
}
