<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;

/**
 * A business's campaigns (CHW-42, B9): whoever runs the business (owner or
 * org admin) sees and sends them, never its staff.
 */
final class CampaignPolicy
{
    use ReadsTenant;

    public function viewAny(User $user, Business $business): bool
    {
        return $this->runs($business->organization_id, $business->id);
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $this->runs($campaign->organization_id, $campaign->business_id);
    }

    public function create(User $user, Business $business): bool
    {
        return $this->runs($business->organization_id, $business->id);
    }
}
