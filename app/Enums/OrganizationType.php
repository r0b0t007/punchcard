<?php

declare(strict_types=1);

namespace App\Enums;

/** ADR 0006: every business belongs to an organization of one of these shapes. */
enum OrganizationType: string
{
    /** One business; the UI hides the organization level. */
    case Independent = 'independent';

    /** An owned chain: one business, many locations. */
    case Chain = 'chain';

    /** A franchise network: one business per franchisee, one shared card program. */
    case Franchise = 'franchise';
}
