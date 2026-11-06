<?php

declare(strict_types=1);

namespace App\Features;

/**
 * Whether a business may use automations (CHW-46, B11), a Premium feature:
 * off until the platform admin turns it on for the business
 * (SetAutomationsAccess), until plans and billing decide it (CHW-52).
 * Scoped to the business (Feature::for($business)), stored by Pennant:
 * nobody is on until stored so.
 *
 * Pennant stores what resolve() returns on a business's first read (an admin
 * page view, the engine's run, any page its owner opens: the sidebar asks),
 * and a stored value wins from then on. So a
 * new default here reaches no business already read: billing sets the flag
 * for each business from its plan, never through resolve() alone, and
 * through SetAutomationsAccess, the one writer: it locks the business and
 * records the change.
 */
final class Automations
{
    public string $name = 'automations';

    public function resolve(): bool
    {
        return false;
    }
}
