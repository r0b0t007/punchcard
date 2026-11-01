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
     * @param  bool  $byOwnTap  the customer's own NFC tap made it: its screen (C3) already says what it unlocked
     * @param  bool  $byScan  staff redeemed with a scan of the customer's QR: the customer is told (CHW-228)
     */
    public function __construct(
        public int $enrollmentId,
        public int $organizationId,
        public int $businessId,
        public array $rewardIds,
        public array $redeemedRewardIds = [],
        public bool $byOwnTap = false,
        public bool $byScan = false,
    ) {}
}
