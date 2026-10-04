<?php

declare(strict_types=1);

namespace App\Support\Nfc;

use App\Enums\TapRejection;
use Closure;
use InvalidArgumentException;

/**
 * Verifies NTAG 424 DNA Secure Unique NFC URLs (NXP AN12196, AES-128,
 * encrypted PICCData, CMAC over an empty MAC input).
 *
 * Two steps, because the UID is encrypted inside the URL: decrypt() reads the
 * UID and counter with the system-wide SDMMetaReadKey; the caller then derives
 * the tag's own SDMFileReadKey from that UID and passes it to verifyMac(), which
 * returns a VerifiedTap. Replay protection (counter above the stamper's last
 * counter) is the caller's job, inside the stamper row lock.
 *
 * Keys, `e` and `c` are #[\SensitiveParameter] so stack traces never carry them.
 */
final class SunVerifier
{
    private const int KEY_BYTES = 16;

    private const int PICC_DATA_HEX = 32;

    private const int CMAC_HEX = 16;

    private const int UID_BYTES = 7;

    /** PICCDataTag: UID mirrored (bit 7), counter mirrored (bit 6), UID length 7 (low nibble). */
    private const int PICC_DATA_TAG = 0xC7;

    /** SV2 prefix for the SDM session MAC key (AN12196 section 3.4.2). */
    private const string SESSION_MAC_PREFIX = "\x3C\xC3\x00\x01\x00\x80";

    /**
     * @param  string  $piccData  the `e` query value: 32 hex characters
     * @param  string  $metaReadKey  16-byte binary SDMMetaReadKey
     *
     * @throws SunVerificationFailed with TapRejection::Malformed
     * @throws InvalidArgumentException when the key is not 16 bytes (a server configuration error)
     */
    public function decrypt(
        #[\SensitiveParameter] string $piccData,
        #[\SensitiveParameter] string $metaReadKey,
    ): SunMessage {
        $this->assertKey($metaReadKey);
        $encrypted = $this->decodeHex($piccData, self::PICC_DATA_HEX);

        $plain = openssl_decrypt(
            $encrypted,
            'aes-128-cbc',
            $metaReadKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            str_repeat("\0", 16),
        );

        // A wrong key or forged data decrypts to noise, which almost never carries this tag byte.
        if ($plain === false || ord($plain[0]) !== self::PICC_DATA_TAG) {
            throw new SunVerificationFailed(TapRejection::Malformed);
        }

        /** @var array{1: int} $counter */
        $counter = unpack('V', substr($plain, 1 + self::UID_BYTES, 3)."\0");

        return $this->message(strtoupper(bin2hex(substr($plain, 1, self::UID_BYTES))), $counter[1]);
    }

    /**
     * @param  string  $cmac  the `c` query value: 16 hex characters
     * @param  string  $fileReadKey  16-byte binary SDMFileReadKey of this tag
     *
     * @throws SunVerificationFailed with TapRejection::Malformed or TapRejection::BadMac
     * @throws InvalidArgumentException when the key is not 16 bytes (a server configuration error)
     */
    public function verifyMac(
        SunMessage $message,
        #[\SensitiveParameter] string $cmac,
        #[\SensitiveParameter] string $fileReadKey,
    ): VerifiedTap {
        $this->assertKey($fileReadKey);
        $given = $this->decodeHex($cmac, self::CMAC_HEX);

        // Only decrypt() creates a SunMessage, so the UID is 14 hex characters and the counter fits 3 bytes.
        if (! hash_equals(self::sessionMac($fileReadKey, $message->uid, $message->counter), $given)) {
            throw new SunVerificationFailed(TapRejection::BadMac);
        }

        return $this->verifiedTap($message->uid, $message->counter);
    }

    /**
     * The 8-byte SUN MAC a tag computes for its UID (14 hex characters) and
     * counter (AN12196 section 3.4.2): a session MAC key from the file read
     * key, a CMAC over the empty input, its odd bytes. Shared with FakeTap so
     * fake taps can never drift from what is verified.
     */
    public static function sessionMac(#[\SensitiveParameter] string $fileReadKey, string $uid, int $counter): string
    {
        $sessionVector = self::SESSION_MAC_PREFIX.hex2bin($uid).substr(pack('V', $counter), 0, 3);
        $full = AesCmac::compute(AesCmac::compute($fileReadKey, $sessionVector), '');
        $truncated = '';

        for ($i = 1; $i < 16; $i += 2) {
            $truncated .= $full[$i];
        }

        return $truncated;
    }

    /** SunMessage and VerifiedTap have private constructors; these closures run in their class scope. */
    private function message(string $uid, int $counter): SunMessage
    {
        return Closure::bind(static fn (): SunMessage => new SunMessage($uid, $counter), null, SunMessage::class)();
    }

    private function verifiedTap(string $uid, int $counter): VerifiedTap
    {
        return Closure::bind(static fn (): VerifiedTap => new VerifiedTap($uid, $counter), null, VerifiedTap::class)();
    }

    /** A wrong key length is a server bug (configuration or key derivation), never a bad tap. */
    private function assertKey(#[\SensitiveParameter] string $key): void
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new InvalidArgumentException('SUN keys must be 16 binary bytes.');
        }
    }

    private function decodeHex(#[\SensitiveParameter] string $hex, int $length): string
    {
        if (strlen($hex) !== $length || ! ctype_xdigit($hex)) {
            throw new SunVerificationFailed(TapRejection::Malformed);
        }

        return (string) hex2bin($hex);
    }
}
