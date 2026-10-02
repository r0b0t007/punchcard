<?php

declare(strict_types=1);

namespace App\Enums;

/** What a reward gives; reward_value is a percent or an amount in centimes. */
enum RewardType: string
{
    /** A free item, described by reward_text. */
    case Item = 'item';

    case Percent = 'percent';

    case Fixed = 'fixed';
}
