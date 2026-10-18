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
        return new self(__('The business or location no longer exists: check it and try again.'));
    }

    /** The platform holds the stamper while its keys are changed (SetStamperStatus): its owner can't switch it on. */
    public static function held(): self
    {
        return new self(__('punchcard support switched this stamper off while its keys are changed. It can be switched on once they are.'));
    }
}
