<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tap;

/**
 * The tap log is platform data (CHW-22): nobody in the app reads or changes
 * it. The platform admin reaches it in Filament (Gate::before); a customer
 * sees only their own result page (TapSession).
 */
final class TapPolicy
{
    public function viewAny(): bool
    {
        return false;
    }

    public function view(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return false;
    }
}
