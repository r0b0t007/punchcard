<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer's card changed: stamps given or taken back, rewards unlocked or
 * redeemed. Dispatched by AddStamps once the stamp is committed, never for a
 * replayed one, and by RedeemReward once the redemption is; queued listeners
 * (Wallet pass updates, CHW-39; the staff live feed, CHW-29) do the slow work,
 * so the tap stays fast. Ids only: a listener reads what it needs in its own
 * tenant.
 */
final readonly class EnrollmentChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $rewardIds  the rewards this change unlocked
     * @param  list<int>  $redeemedRewardIds  the rewards this change redeemed
     */
    public function __construct(
        public int $enrollmentId,
        public int $organizationId,
        public int $businessId,
        public array $rewardIds,
        public array $redeemedRewardIds = [],
    ) {}
}
