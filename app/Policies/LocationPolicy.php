<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;

/**
 * A business's locations (CHW-22). Its people see them; only its owner or
 * org admin creates, changes or closes one: a location's timezone sets the
 * daily cap's day, so staff never change it.
 */
final class LocationPolicy
{
    use ReadsTenant;

    public function view(User $user, Location $location): bool
    {
        return $this->sees($location->organization_id, $location->business_id);
    }

    public function create(User $user, Business $business): bool
    {
        return $this->runs($business->organization_id, $business->id);
    }

    public function update(User $user, Location $location): bool
    {
        return $this->runs($location->organization_id, $location->business_id);
    }

    public function delete(User $user, Location $location): bool
    {
        return $this->runs($location->organization_id, $location->business_id);
    }
}
