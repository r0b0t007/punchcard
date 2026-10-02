<?php

declare(strict_types=1);

namespace App\Support\Nfc;

use InvalidArgumentException;

/**
 * AES-128 CMAC as specified in RFC 4493.
 */
final class AesCmac
{
    private const int BLOCK = 16;

    /** Rb for 128-bit blocks (RFC 4493 section 2.3). */
    private const int RB = 0x87;

    /**
     * @param  string  $key  16-byte binary key
     * @param  string  $message  binary message, may be empty
     * @return string 16-byte binary MAC
     */
    public static function compute(#[\SensitiveParameter] string $key, string $message): string
    {
        [$k1, $k2] = self::subkeys($key);

        $blocks = max(1, (int) ceil(strlen($message) / self::BLOCK));
        $lastIsComplete = $message !== '' && strlen($message) % self::BLOCK === 0;
        $last = substr($message, ($blocks - 1) * self::BLOCK);
        $last = $lastIsComplete
            ? $last ^ $k1
            : str_pad($last."\x80", self::BLOCK, "\0") ^ $k2;

        $x = str_repeat("\0", self::BLOCK);

        for ($i = 0; $i < $blocks - 1; $i++) {
            $x = self::encryptBlock($key, $x ^ substr($message, $i * self::BLOCK, self::BLOCK));
        }

        return self::encryptBlock($key, $x ^ $last);
    }

    /**
     * The CMAC subkeys K1 and K2 (RFC 4493 section 2.3). AN10922 key
     * diversification needs them directly, because it pads to 32 bytes.
     *
     * @param  string  $key  16-byte binary key
     * @return array{0: string, 1: string} K1 and K2
     */
    public static function subkeys(#[\SensitiveParameter] string $key): array
    {
        if (strlen($key) !== self::BLOCK) {
            throw new InvalidArgumentException('AES-128 key must be 16 bytes.');
        }

        $k1 = self::subkey(self::encryptBlock($key, str_repeat("\0", self::BLOCK)));

        return [$k1, self::subkey($k1)];
    }

    /** Doubles a block in GF(2^128): shift left one bit, XOR Rb when the top bit was set. */
    private static function subkey(#[\SensitiveParameter] string $block): string
    {
        $shifted = '';
        $carry = 0;

        for ($i = self::BLOCK - 1; $i >= 0; $i--) {
            $byte = ord($block[$i]);
            $shifted = chr((($byte << 1) & 0xFF) | $carry).$shifted;
            $carry = ($byte >> 7) & 1;
        }

        if ((ord($block[0]) & 0x80) !== 0) {
            $shifted[self::BLOCK - 1] = chr(ord($shifted[self::BLOCK - 1]) ^ self::RB);
        }

        return $shifted;
    }

    private static function encryptBlock(#[\SensitiveParameter] string $key, string $block): string
    {
        $encrypted = openssl_encrypt($block, 'aes-128-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);

        if ($encrypted === false) {
            throw new InvalidArgumentException('AES block encryption failed.');
        }

        return $encrypted;
    }
}
