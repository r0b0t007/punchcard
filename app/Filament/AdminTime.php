<?php

declare(strict_types=1);

namespace App\Filament;

/**
 * How the admin panel shows a moment (CHW-34): with its zone named, UTC for
 * platform times, a site's own zone for its history.
 */
final class AdminTime
{
    public const string FORMAT = 'j M Y, H:i T';
}
