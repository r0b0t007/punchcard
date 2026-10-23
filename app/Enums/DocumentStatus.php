<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Where a verification document stands (CHW-145): sent, then the platform's decision. Labelled for A1. */
enum DocumentStatus: string implements HasColor, HasLabel
{
    /** Sent by the business, waiting for the platform admin. */
    case Submitted = 'submitted';

    /** The business was verified with it. */
    case Accepted = 'accepted';

    /** The platform admin asked for a new one, with a reason. */
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Submitted => __('Waiting'),
            self::Accepted => __('Accepted'),
            self::Rejected => __('Rejected'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Submitted => 'warning',
            self::Accepted => 'success',
            self::Rejected => 'danger',
        };
    }
}
