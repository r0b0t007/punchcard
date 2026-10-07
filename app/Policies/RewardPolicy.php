<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Reward;
use App\Models\User;
use App\Policies\Concerns\ReadsProgram;

/**
 * A customer's reward in the portal (CHW-22), as Reward's scope shows it:
 * the org admin sees the program's; the owner of a business sees those of
 * customers who were there, and those redeemed there (the "redeemed here"
 * report, ADR 0006). Redeeming needs the customer at the counter: their tap
 * today (RedeemReward), a staff scan of their member token in CHW-30.
 */
final class RewardPolicy
{
    use ReadsProgram;

    public function viewAny(): bool
    {
        return $this->managesCustomers();
    }

    public function view(User $user, Reward $reward): bool
    {
        $businessId = $this->tenant()->businessId();

        return $this->administers($reward->organization_id)
            || $this->runsWhereSeen($reward->enrollment_id)
            || ($businessId !== null && $reward->redeemed_business_id === $businessId && $this->runs($reward->organization_id, $businessId));
    }
}
