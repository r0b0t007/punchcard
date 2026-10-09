<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * A business pausing its stamper: a disabled one rejects every tap and keeps
 * the tag's assignment. A lost or stolen tag is retired on nfc_tags instead,
 * and replacing a tag ends the assignment (stampers.unassigned_at).
 */
enum StamperStatus: string implements HasLabel
{
    case Active = 'active';

    case Disabled = 'disabled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Enabled'),
            self::Disabled => __('Disabled'),
        };
    }
}
