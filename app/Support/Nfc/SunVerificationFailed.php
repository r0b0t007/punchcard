<?php

declare(strict_types=1);

namespace App\Support\Nfc;

use RuntimeException;

/**
 * Thrown when a SUN URL fails verification. The message never contains the
 * tap parameters, the UID or any key, so it is safe to log.
 */
final class SunVerificationFailed extends RuntimeException
{
    public function __construct(public readonly SunFailure $reason)
    {
        parent::__construct('SUN verification failed: '.$reason->value);
    }
}
