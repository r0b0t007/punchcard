<?php

declare(strict_types=1);

namespace App\Enums;

/** A customer's chip on B4 and B5 (CHW-44): lost first, then going quiet, else active. */
enum CustomerStatus: string
{
    case Active = 'active';

    case Quiet = 'quiet';

    case Lost = 'lost';
}
