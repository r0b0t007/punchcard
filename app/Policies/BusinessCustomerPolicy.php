<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Business;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;

/** A business's customers (CHW-44, B4, B5): whoever runs the business, never its staff. */
final class BusinessCustomerPolicy
{
    use ReadsTenant;

    public function viewAny(User $user, Business $business): bool
    {
        return $this->runs($business->organization_id, $business->id);
    }
}
