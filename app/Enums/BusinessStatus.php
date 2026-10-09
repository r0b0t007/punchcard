<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a business stands with the platform (CHW-34): pending until the
 * admin verifies it (it works meanwhile, CHW-22), verified, or suspended
 * (its counter and people stop). Set only by the admin's Actions.
 */
enum BusinessStatus: string implements HasColor, HasLabel
{
    /** Signed up, waiting for the admin verification queue. */
    case Pending = 'pending';
    case Verified = 'verified';
    case Suspended = 'suspended';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Verified => __('Verified'),
            self::Suspended => __('Suspended'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Verified => 'success',
            self::Suspended => 'danger',
        };
    }
}
