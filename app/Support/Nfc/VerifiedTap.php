<?php

declare(strict_types=1);

namespace App\Support\Nfc;

use LogicException;

/**
 * A tap whose CMAC passed. Only SunVerifier::verifyMac() creates one: the
 * constructor is private and the object refuses serialization, so code that
 * stamps or moves a stamper's counter can require this type and cannot get one
 * by accident. That guards against mistakes, not a deliberate bypass (reflection
 * can still build one), so never accept a VerifiedTap from outside the request
 * that verified it. Replay is not checked yet: the caller compares the counter
 * under the stamper row lock.
 */
final readonly class VerifiedTap
{
    /**
     * @param  string  $uid  7-byte tag UID as 14 uppercase hex characters
     * @param  int  $counter  SDMReadCtr, 0 to 16777215
     */
    private function __construct(
        public string $uid,
        public int $counter,
    ) {}

    /**
     * Never serialized: a session or cache round trip would rebuild the object
     * without the verifier, so store `e` and `c` and verify them again instead.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('VerifiedTap cannot be serialized.');
    }

    /** @param  array<string, mixed>  $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('VerifiedTap cannot be unserialized.');
    }
}
