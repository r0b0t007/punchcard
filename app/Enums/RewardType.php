<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** What a reward gives; reward_value is a percent or an amount in centimes. */
enum RewardType: string implements HasLabel
{
    /** A free item, described by reward_text. */
    case Item = 'item';

    case Percent = 'percent';

    case Fixed = 'fixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Item => __('Free item'),
            self::Percent => __('% off'),
            self::Fixed => __('Fixed amount'),
        };
    }
}
