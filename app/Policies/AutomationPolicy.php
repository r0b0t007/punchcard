<?php

declare(strict_types=1);

namespace App\Policies;

use App\Features\Automations;
use App\Models\Business;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;
use Laravel\Pennant\Feature;

/**
 * A business's automations (CHW-46, B11): whoever runs the business (owner
 * or org admin) sees and sets them, never its staff, and only while the
 * business is allowed automations (App\Features\Automations, a Premium
 * feature switched by the platform admin).
 */
final class AutomationPolicy
{
    use ReadsTenant;

    public function viewAny(User $user, Business $business): bool
    {
        return $this->runs($business->organization_id, $business->id) && Feature::for($business)->active(Automations::class);
    }

    public function update(User $user, Business $business): bool
    {
        return $this->viewAny($user, $business);
    }
}
