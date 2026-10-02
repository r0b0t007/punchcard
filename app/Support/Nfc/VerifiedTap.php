<?php

declare(strict_types=1);

namespace App\Support\Nfc;

/**
 * A tap whose CMAC passed. Only SunVerifier::verifyMac() creates one, so code
 * that stamps or moves a stamper's counter can require this type. Replay is
 * not checked yet: the caller compares the counter under the stamper row lock.
 */
final readonly class VerifiedTap
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
