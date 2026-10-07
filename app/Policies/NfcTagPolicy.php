<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\DeniesEveryone;

/**
 * NFC tags and their keys are platform data (CHW-22): provisioned and
 * assigned by the platform admin in Filament (Gate::before), never deleted
 * (Invariants, CLAUDE.md). Nobody in the app reads or changes them.
 */
final class NfcTagPolicy
{
    use DeniesEveryone;
}
