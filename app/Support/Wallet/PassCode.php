<?php

declare(strict_types=1);

namespace App\Support\Wallet;

use SensitiveParameter;

/**
 * A Wallet pass's code (CHW-38): PW1.{serial}.{mac}, the QR on the pass that
 * staff scan at the counter like the member QR. A pass can't change every
 * 30 seconds as the member QR does (MemberQrToken), so the code stays the same
 * for the pass's life; what it counts for is decided where it is scanned (once
 * per business for a while, never once the pass is revoked). The serial names
 * the pass, not the customer; the mac is the first 16 bytes of an HMAC-SHA256
 * under a key of its own taken from the app's (APP_KEY), base64url. Anyone
 * can read it; nobody can change it or make one, and each has exactly one
 * spelling.
 */
final class PassCode
{
    public const string VERSION = 'PW1';

    /** A serial: 13 random bytes, as lowercase hex. */
    public static function newSerial(): string
    {
        return bin2hex(random_bytes(13));
    }

    public static function of(string $serial): string
    {
        return self::VERSION.'.'.$serial.'.'.self::mac($serial);
    }

    /**
     * A code's serial and mac, if it has a code's shape (the mac unchecked).
     *
     * @return array{serial: string, mac: string}|null
     */
    public static function fields(#[SensitiveParameter] string $code): ?array
    {
        if (preg_match('/^PW1\.([0-9a-f]{26})\.([A-Za-z0-9_-]{22})$/D', $code, $parts) !== 1) {
            return null;
        }

        return ['serial' => $parts[1], 'mac' => $parts[2]];
    }

    /**
     * Whether a code's parts carry its own signature (compared in constant time).
     *
     * @param  array{serial: string, mac: string}  $fields
     */
    public static function authentic(array $fields): bool
    {
        return hash_equals(self::mac($fields['serial']), $fields['mac']);
    }

    private static function mac(string $serial): string
    {
        $key = hash_hmac('sha256', 'wallet-pass-code', (string) config('app.key'), true);
        $mac = substr(hash_hmac('sha256', self::VERSION.'|'.$serial, $key, true), 0, 16);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
