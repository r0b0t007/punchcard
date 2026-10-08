<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use RuntimeException;

/** A tag can't be registered, moved or changed as asked; the message says why, and never carries key material. */
final class StamperRefused extends RuntimeException
{
    /** The business or location chosen was deleted while the admin was choosing it. */
    public static function siteGone(): self
    {
        return new self('The business or location no longer exists: check it and try again.');
    }
}
