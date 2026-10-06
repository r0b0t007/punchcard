<?php

declare(strict_types=1);

namespace App\Enums;

/** Why a reward was not redeemed, or its redeem window not opened (CHW-26). */
enum RedeemRefusal: string
{
    /** The reward is on someone else's card. */
    case NotYours = 'not_yours';

    /** Already redeemed, or expired. */
    case Unavailable = 'unavailable';

    /** Redeeming needs a verified email: an unverified account can collect, never cash out. */
    case Unverified = 'unverified';

    /** The presence (a tap) is not inside the redeem window. */
    case OutsideWindow = 'outside_window';

    /** The business where the customer is does not honour the reward's card. */
    case NotHonoured = 'not_honoured';

    /** That business or location is archived. */
    case SiteClosed = 'site_closed';
}
