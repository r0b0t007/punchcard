<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\DeniesEveryone;

/**
 * The tap log is platform data (CHW-22): nobody in the app reads or changes
 * it. The platform admin reads it in Filament (Gate::before) and never edits
 * or deletes a tap (Invariants); a customer sees only their own result page
 * (TapSession).
 */
final class TapPolicy
{
    use DeniesEveryone;
}
