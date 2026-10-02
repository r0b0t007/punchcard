<?php

declare(strict_types=1);

namespace App\Enums;

/** A reward is available once unlocked, then redeemed once or expired. */
enum RewardStatus: string
{
    case Available = 'available';

    case Redeemed = 'redeemed';

    case Expired = 'expired';
}
