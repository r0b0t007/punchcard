<?php

declare(strict_types=1);

namespace App\Features;

/**
 * Whether a business may use automations (CHW-46, B11), a Premium feature:
 * off until the platform admin turns it on for the business
 * (SetAutomationsAccess), until plans and billing decide it (CHW-52).
 * Scoped to the business (Feature::for($business)), stored by Pennant:
 * nobody is on until stored so.
 */
final class Automations
{
    public string $name = 'automations';

    public function resolve(): bool
    {
        return false;
    }
}
