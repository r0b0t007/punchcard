<?php

declare(strict_types=1);

namespace App\Enums;

/** A user's role inside one business (business_user). */
enum BusinessRole: string
{
    /** Runs the business: locations, stampers, staff, local stats and campaigns. */
    case Owner = 'owner';

    /** Stamps, arms, scans and redeems; optionally limited to one location. */
    case Staff = 'staff';
}
