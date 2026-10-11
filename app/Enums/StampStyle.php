<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a card draws a filled stamp (resources/js/components/loyalty-card/stamp.tsx).
 * Postgres checks the stored value against the same list.
 */
enum StampStyle: string
{
    case Dot = 'dot';
    case Ring = 'ring';
    case Check = 'check';
    case Heart = 'heart';
    case Star = 'star';
    case Logo = 'logo';
}
