<?php

declare(strict_types=1);

namespace App\Enums;

enum BusinessStatus: string
{
    /** Signed up, waiting for the admin verification queue. */
    case Pending = 'pending';
    case Verified = 'verified';
    case Suspended = 'suspended';
}
