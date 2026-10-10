<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** How a loyalty card turns stamps into rewards. */
enum CardMode: string implements HasLabel
{
    /** A reward every stamps_required stamps; the card resets, extra stamps carry over. */
    case Cyclic = 'cyclic';

    /** Lifetime stamps unlock each tier's reward once; the card never resets. */
    case Progressive = 'progressive';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cyclic => __('Cyclic'),
            self::Progressive => __('Progressive'),
        };
    }
}
