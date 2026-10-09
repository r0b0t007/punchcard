<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** ADR 0006: every business belongs to an organization of one of these shapes. */
enum OrganizationType: string implements HasLabel
{
    /** One business; the UI hides the organization level. */
    case Independent = 'independent';

    /** An owned chain: one business, many locations. */
    case Chain = 'chain';

    /** A franchise network: one business per franchisee, one shared card program. */
    case Franchise = 'franchise';

    public function getLabel(): string
    {
        return match ($this) {
            self::Independent => __('Independent'),
            self::Chain => __('Chain'),
            self::Franchise => __('Franchise'),
        };
    }
}
