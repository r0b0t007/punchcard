<?php

declare(strict_types=1);

namespace App\Support\Wallet;

use LogicException;

/**
 * The Apple Wallet settings (config punchcard.wallet.apple): the pass type and
 * team, and where the Pass Type ID certificate (.p12) and Apple's WWDR
 * intermediate are kept, outside the repository (storage/app/private/wallet-certs
 * by convention). Complete once all are set; the Add to Apple Wallet badge
 * shows only then.
 */
final class AppleWalletConfig
{
    public static function isComplete(): bool
    {
        foreach (['pass_type_id', 'team_id', 'cert_path', 'wwdr_cert_path'] as $key) {
            if (! is_string(config('punchcard.wallet.apple.'.$key)) || config('punchcard.wallet.apple.'.$key) === '') {
                return false;
            }
        }

        return true;
    }

    public static function passTypeId(): string
    {
        return self::string('pass_type_id');
    }

    public static function teamId(): string
    {
        return self::string('team_id');
    }

    public static function certPath(): string
    {
        return self::string('cert_path');
    }

    public static function certPassword(): string
    {
        $password = config('punchcard.wallet.apple.cert_password');

        return is_string($password) ? $password : '';
    }

    public static function wwdrCertPath(): string
    {
        return self::string('wwdr_cert_path');
    }

    private static function string(string $key): string
    {
        $value = config('punchcard.wallet.apple.'.$key);

        if (! is_string($value) || $value === '') {
            throw new LogicException("Apple Wallet is not set up: punchcard.wallet.apple.{$key} is empty.");
        }

        return $value;
    }
}
