<?php

declare(strict_types=1);

namespace App\Support\Nfc;

/**
 * What a tag mirrored into its SUN URL: its UID and read counter. Only trust it
 * after SunVerifier::verifyMac() has accepted the CMAC.
 */
final readonly class SunMessage
{
    /**
     * @param  string  $uid  7-byte tag UID as 14 uppercase hex characters
     * @param  int  $counter  SDMReadCtr, 0 to 16777215
     */
    public function __construct(
        public string $uid,
        public int $counter,
    ) {}
}
