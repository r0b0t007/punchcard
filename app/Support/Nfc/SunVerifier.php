<?php

declare(strict_types=1);

namespace App\Support\Nfc;

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

    /** SDMReadCtr is 3 bytes. */
    private const int MAX_COUNTER = 0xFFFFFF;

    /** PICCDataTag: UID mirrored (bit 7), counter mirrored (bit 6), UID length 7 (low nibble). */
    private const int PICC_DATA_TAG = 0xC7;

    /** SV2 prefix for the SDM session MAC key (AN12196 section 3.4.2). */
    private const string SESSION_MAC_PREFIX = "\x3C\xC3\x00\x01\x00\x80";

    /**
     * @param  string  $piccData  the `e` query value: 32 hex characters
     * @param  string  $metaReadKey  16-byte binary SDMMetaReadKey
     *
     * @throws SunVerificationFailed with SunFailure::Malformed
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
            throw new SunVerificationFailed(SunFailure::Malformed);
        }

        /** @var array{1: int} $counter */
        $counter = unpack('V', substr($plain, 1 + self::UID_BYTES, 3)."\0");

        return new SunMessage(
            uid: strtoupper(bin2hex(substr($plain, 1, self::UID_BYTES))),
            counter: $counter[1],
        );
    }

    /**
     * @param  string  $cmac  the `c` query value: 16 hex characters
     * @param  string  $fileReadKey  16-byte binary SDMFileReadKey of this tag
     *
     * @throws SunVerificationFailed with SunFailure::Malformed or SunFailure::BadMac
     */
    public function verifyMac(
        SunMessage $message,
        #[\SensitiveParameter] string $cmac,
        #[\SensitiveParameter] string $fileReadKey,
    ): VerifiedTap {
        $this->assertKey($fileReadKey);
        $given = $this->decodeHex($cmac, self::CMAC_HEX);

        $uid = $this->decodeHex($message->uid, self::UID_BYTES * 2);

        if ($message->counter < 0 || $message->counter > self::MAX_COUNTER) {
            throw new SunVerificationFailed(SunFailure::Malformed);
        }

        $counter = substr(pack('V', $message->counter), 0, 3);

        $sessionKey = AesCmac::compute($fileReadKey, self::SESSION_MAC_PREFIX.$uid.$counter);
        $full = AesCmac::compute($sessionKey, '');

        $truncated = '';

        for ($i = 1; $i < 16; $i += 2) {
            $truncated .= $full[$i];
        }

        if (! hash_equals($truncated, $given)) {
            throw new SunVerificationFailed(SunFailure::BadMac);
        }

        return new VerifiedTap($message->uid, $message->counter);
    }

    private function assertKey(#[\SensitiveParameter] string $key): void
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new SunVerificationFailed(SunFailure::Malformed);
        }
    }

    private function decodeHex(#[\SensitiveParameter] string $hex, int $length): string
    {
        if (strlen($hex) !== $length || ! ctype_xdigit($hex)) {
            throw new SunVerificationFailed(SunFailure::Malformed);
        }

        return (string) hex2bin($hex);
    }
}
