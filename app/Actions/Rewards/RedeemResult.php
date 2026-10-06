<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Models\Reward;

/**
 * A redemption: the reward, and whether this call redeemed it. Asking again
 * for a reward already redeemed returns its first redemption with
 * redeemedNow false, never a second one: a caller hands over nothing then
 * (ApplyTap refuses the tap; a staff scan shows "already redeemed at").
 */
final readonly class RedeemResult
{
    public function __construct(
        public Reward $reward,
        public bool $redeemedNow,
    ) {}
}
