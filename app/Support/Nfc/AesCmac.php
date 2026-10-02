<?php

declare(strict_types=1);

namespace App\Support\Nfc;

use InvalidArgumentException;
use RuntimeException;

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
     * @param  int  $minBlocks  pad the message to at least this many blocks. 1 is RFC 4493; NXP AN10922 key
     *                          diversification is the same MAC over at least 2 blocks (32 bytes).
     * @return string 16-byte binary MAC
     */
    public static function compute(#[\SensitiveParameter] string $key, string $message, int $minBlocks = 1): string
    {
        if (strlen($key) !== self::BLOCK) {
            throw new InvalidArgumentException('AES-128 key must be 16 bytes.');
        }

        if ($minBlocks < 1) {
            throw new InvalidArgumentException('CMAC needs at least one block.');
        }

        $k1 = self::double(self::encryptBlock($key, str_repeat("\0", self::BLOCK)));
        $k2 = self::double($k1);

        // A complete last block is XORed with K1; otherwise pad with 80 00.. (to $minBlocks at least) and use K2.
        $padded = strlen($message) % self::BLOCK !== 0 || strlen($message) < $minBlocks * self::BLOCK;

        if ($padded) {
            $message .= "\x80";
            $message = str_pad($message, max($minBlocks, (int) ceil(strlen($message) / self::BLOCK)) * self::BLOCK, "\0");
        }

        $blocks = intdiv(strlen($message), self::BLOCK);
        $x = str_repeat("\0", self::BLOCK);

        for ($i = 0; $i < $blocks - 1; $i++) {
            $x = self::encryptBlock($key, $x ^ substr($message, $i * self::BLOCK, self::BLOCK));
        }

        return self::encryptBlock($key, $x ^ substr($message, -self::BLOCK) ^ ($padded ? $k2 : $k1));
    }

    /** Doubles a block in GF(2^128) to derive K1 and K2: shift left one bit, XOR Rb when the top bit was set. */
    private static function double(#[\SensitiveParameter] string $block): string
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
            throw new RuntimeException('AES block encryption failed.');
        }

        return $encrypted;
    }
}
