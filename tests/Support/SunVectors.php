<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Nfc\SunVerificationFailed;
use RuntimeException;

/**
 * Fixed SUN and AES-CMAC test vectors shared by the NFC tests.
 *
 * The AN12196 and RFC 4493 values are published examples. The other taps were
 * generated once with the skill's independent reference implementation
 * (.claude/skills/sun-nfc-verification/reference.php) and stored here, so the
 * tests do not depend on the code they check. None of these are real keys;
 * each hex line carries a gitleaks:allow marker for that reason.
 */
final class SunVectors
{
    /** NXP AN12196 example, all-zero keys. */
    public const string AN12196_PICC_DATA = 'EF963FF7828658A599F3041510671E88'; // gitleaks:allow

    public const string AN12196_CMAC = '94EED9EE65337086'; // gitleaks:allow

    public const string AN12196_UID = '04DE5F1EACC040';

    public const int AN12196_COUNTER = 61;

    /** RFC 4493 section 4 key and the 64-byte message its examples take prefixes of. */
    public const string RFC4493_KEY = '2b7e151628aed2a6abf7158809cf4f3c'; // gitleaks:allow

    public const string RFC4493_MESSAGE = '6bc1bee22e409f96e93d7e117393172aae2d8a571e03ac9c9eb76fac45af8e51' // gitleaks:allow
        .'30c81c46a35ce411e5fbc1191a0a52eff69f2445df4f9b17ad2b417be66c3710'; // gitleaks:allow

    /** Binary test keys for the generated taps (shown as hex). */
    public const string META_KEY_HEX = '8F13D2A47C6E0B5591E8A3C4F0172B6D'; // gitleaks:allow

    public const string FILE_KEY_HEX = '3E9A51C7D20F84B6A1E3576C9D08F42B'; // gitleaks:allow

    public const string UID = '04A1B2C3D4E5F6';

    /**
     * Genuine taps under META_KEY_HEX / FILE_KEY_HEX: [uid, counter, e, c].
     *
     * @var array<string, array{0: string, 1: int, 2: string, 3: string}>
     */
    public const array TAPS = [
        'round trip' => [self::UID, 1234, '6728621048529111CFAFFBF2117B83CD', '790BE17E818459B5'], // gitleaks:allow
        'counter zero' => [self::UID, 0, '2F755BD912011A67FE8DDEED7DE87CC5', 'AFB3E5A1289429BE'], // gitleaks:allow
        'counter max' => [self::UID, 0xFFFFFF, '427BBE8A20B02C677C0B263508F63F83', '6FB1872B65423573'], // gitleaks:allow
        'counter 61' => [self::UID, 61, 'D82B2C12A0E84A74D868E0A4F34306F8', 'AEC1A8F4D45751CF'], // gitleaks:allow
        'counter 62' => [self::UID, 62, 'CBB47B5435F05BA09C537A15D4ACEA6E', '677B1510B79F6F3D'], // gitleaks:allow
        'other UID' => ['04A1B2C3D4E5F7', 61, '9FBDEFAD9FB5B9923D974CB8841F063C', '6A2988CD70A25F9C'], // gitleaks:allow
    ];

    /**
     * PICCData under META_KEY_HEX whose tag byte is not 0xC7 (UID 04A1B2C3D4E5F6, counter 5).
     *
     * @var array<string, string>
     */
    public const array WRONG_TAG_BYTE = [
        'counter not mirrored (0x87)' => 'AC7C38B1D986ABC402089DBDEBF55B47', // gitleaks:allow
        'UID not mirrored (0x47)' => '0C235270D5704EA22BCC60821F8E4779', // gitleaks:allow
        '4-byte UID (0xC4)' => '56B189155C0441ED81108EDF12B30135', // gitleaks:allow
    ];

    public static function zeroKey(): string
    {
        return str_repeat("\0", 16);
    }

    public static function metaKey(): string
    {
        return (string) hex2bin(self::META_KEY_HEX);
    }

    public static function fileKey(): string
    {
        return (string) hex2bin(self::FILE_KEY_HEX);
    }

    /**
     * @return array{uid: string, counter: int, e: string, c: string}
     */
    public static function tap(string $name): array
    {
        [$uid, $counter, $e, $c] = self::TAPS[$name];

        return ['uid' => $uid, 'counter' => $counter, 'e' => $e, 'c' => $c];
    }

    /** Runs the call and returns the SunVerificationFailed it must throw. */
    public static function failure(callable $call): SunVerificationFailed
    {
        try {
            $call();
        } catch (SunVerificationFailed $failure) {
            return $failure;
        }

        throw new RuntimeException('Expected SunVerificationFailed');
    }
}
