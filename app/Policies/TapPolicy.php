<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\DeniesEveryone;

/**
 * The tap log is platform data (CHW-22): nobody in the app reads or changes
 * it. The platform admin reads it in Filament (Gate::before), and may fix or
 * erase a row (a data-erasure request); a customer sees only their own
 * result page (TapSession).
 */
final class TapPolicy
{
    use DeniesEveryone;
}
