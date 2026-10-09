<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** A user's role inside one business (business_user). */
enum BusinessRole: string implements HasLabel
{
    /** Runs the business: locations, stampers, staff, local stats and campaigns. */
    case Owner = 'owner';

    /** Stamps, arms, scans and redeems; optionally limited to one location. */
    case Staff = 'staff';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Staff => __('Staff'),
        };
    }
}
