<?php

declare(strict_types=1);

namespace App\Support\Nfc;

/**
 * What a tag mirrored into its SUN URL: its UID and read counter. Only
 * SunVerifier::decrypt() creates one, so the values are always well formed.
 * They are not trusted yet: pass the message to SunVerifier::verifyMac().
 */
final readonly class SunMessage
{
    /**
     * @param  string  $uid  7-byte tag UID as 14 uppercase hex characters
     * @param  int  $counter  SDMReadCtr, 0 to 16777215
     */
    private function __construct(
        public string $uid,
        public int $counter,
    ) {}
}
