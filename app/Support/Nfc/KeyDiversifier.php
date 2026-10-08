<?php

declare(strict_types=1);

namespace App\Support\Nfc;

use InvalidArgumentException;
use SensitiveParameterValue;

/**
 * Derives NTAG 424 DNA keys from one master key with NXP AN10922 AES-128
 * diversification, so no per-tag secret is stored.
 *
 * Inputs start with the tag's key number, so two keys can never collide:
 *   SDMMetaReadKey (system-wide): keyNo || "punchcard" || version
 *   per-tag keys:                 keyNo || UID || "punchcard" || version
 * The meta read key cannot depend on the UID, because the UID is encrypted
 * under it. Changing this layout invalidates every provisioned tag; the golden
 * keys in the tests pin it. Key handling is in docs/runbooks/stamper-keys.md.
 */
final readonly class KeyDiversifier
{
    /** Application master key: authorizes changing the tag's keys and settings. */
    public const int APP_MASTER_KEY = 0;

    /** SDMMetaReadKey: decrypts PICCData (`e`). The same on every tag. */
    public const int META_READ_KEY = 1;

    /** SDMFileReadKey: signs the CMAC (`c`). Unique per tag. */
    public const int FILE_READ_KEY = 2;

    private const int MAX_KEY_NUMBER = 4;

    private const string SYSTEM_IDENTIFIER = 'punchcard';

    /** AN10922 diversification constant for AES-128 keys. */
    private const string AES128_CONSTANT = "\x01";

    private const int KEY_BYTES = 16;

    /** Versions are two bytes in the derivation input: 1 to 65535. */
    public const int MAX_KEY_VERSION = 0xFFFF;

    private const int UID_HEX = TagUid::BYTES * 2;

    /** Wrapped so dumps (dd, VarDumper, var_export, print_r) and serialization never reveal it. */
    private SensitiveParameterValue $masterKey;

    private function __construct(#[\SensitiveParameter] string $masterKey)
    {
        $this->masterKey = new SensitiveParameterValue($masterKey);
    }

    /**
     * @param  string|null  $hex  NFC_SUN_MASTER_KEY: 32 hex characters
     *
     * @throws InvalidArgumentException when the master key is missing or malformed
     */
    public static function fromHex(#[\SensitiveParameter] ?string $hex): self
    {
        if ($hex === null || strlen($hex) !== self::KEY_BYTES * 2 || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException('NFC_SUN_MASTER_KEY must be 32 hex characters.');
        }

        return new self((string) hex2bin($hex));
    }

    /**
     * NXP AN10922 AES-128 diversification: the CMAC of D = 0x01 || input, with D
     * always padded to 32 bytes (80 00 .., subkey K2) unless it already is 32
     * bytes (K1). Plain CMAC pads only to the next 16 bytes, which gives a
     * different key for inputs under 16 bytes, hence minBlocks 2.
     *
     * @param  string  $key  16-byte binary key
     * @param  string  $input  diversification input M, 1 to 31 bytes
     * @return string 16-byte binary key
     */
    public static function diversify(#[\SensitiveParameter] string $key, string $input): string
    {
        if ($input === '' || strlen($input) > 31) {
            throw new InvalidArgumentException('AN10922 diversification input must be 1 to 31 bytes.');
        }

        return AesCmac::compute($key, self::AES128_CONSTANT.$input, minBlocks: 2);
    }

    /** The system-wide SDMMetaReadKey for this meta key version. */
    public function metaReadKey(int $version): string
    {
        return self::diversify(
            $this->masterKey->getValue(),
            chr(self::META_READ_KEY).self::SYSTEM_IDENTIFIER.$this->versionBytes($version),
        );
    }

    /** The tag's SDMFileReadKey for its stamper key_version. */
    public function fileReadKey(string $uid, int $version): string
    {
        return $this->tagKey(self::FILE_READ_KEY, $uid, $version);
    }

    /**
     * A per-tag key: any key number except the system-wide meta read key.
     *
     * @param  string  $uid  7-byte tag UID as 14 hex characters, either case
     */
    public function tagKey(int $keyNumber, string $uid, int $version): string
    {
        if ($keyNumber < 0 || $keyNumber > self::MAX_KEY_NUMBER || $keyNumber === self::META_READ_KEY) {
            throw new InvalidArgumentException('Per-tag keys are key numbers 0, 2, 3 and 4.');
        }

        if (strlen($uid) !== self::UID_HEX || ! ctype_xdigit($uid)) {
            throw new InvalidArgumentException('A tag UID is 14 hex characters.');
        }

        return self::diversify(
            $this->masterKey->getValue(),
            chr($keyNumber).hex2bin($uid).self::SYSTEM_IDENTIFIER.$this->versionBytes($version),
        );
    }

    private function versionBytes(int $version): string
    {
        if ($version < 1 || $version > self::MAX_KEY_VERSION) {
            throw new InvalidArgumentException('Key versions are 1 to '.self::MAX_KEY_VERSION.'.');
        }

        return pack('n', $version);
    }
}
