<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

/**
 * Platform data (tags, the tap log, CHW-22): nobody in the app reads or
 * changes it. The platform admin reaches it in Filament (Gate::before),
 * never past App\Policies\Invariants.
 */
trait DeniesEveryone
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
