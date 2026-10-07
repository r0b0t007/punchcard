<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * NFC tags and their keys are platform data (CHW-22): provisioned and
 * assigned by the platform admin, in Filament (Gate::before); never deleted
 * (CLAUDE.md). Nobody in the app reads or changes them.
 */
final class NfcTagPolicy
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
