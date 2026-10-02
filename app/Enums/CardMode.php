<?php

declare(strict_types=1);

namespace App\Enums;

/** How a loyalty card turns stamps into rewards. */
enum CardMode: string
{
    /** A reward every stamps_required stamps; the card resets, extra stamps carry over. */
    case Cyclic = 'cyclic';

    /** Lifetime stamps unlock each tier's reward once; the card never resets. */
    case Progressive = 'progressive';
}
