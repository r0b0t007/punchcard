<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a stamper kit order is (CHW-31). Requested by the owner in the
 * onboarding wizard; fulfilment and tracking (shipped, delivered) come with
 * CHW-57.
 */
enum KitOrderStatus: string
{
    case Requested = 'requested';
}
