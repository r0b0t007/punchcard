<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Business;
use App\Models\KitOrder;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;

/**
 * Stamper kit orders (CHW-31): seen where the business is seen, requested
 * and corrected by whoever runs it (its owner, or the org admin).
 */
final class KitOrderPolicy
{
    use ReadsTenant;

    public function view(User $user, KitOrder $order): bool
    {
        return $this->sees($order->organization_id, $order->business_id);
    }

    public function create(User $user, Business $business): bool
    {
        return $this->runs($business->organization_id, $business->id);
    }

    public function update(User $user, KitOrder $order): bool
    {
        return $this->runs($order->organization_id, $order->business_id);
    }
}
