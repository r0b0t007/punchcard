<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a tap on /t stands (CHW-25). */
enum TapStatus: string
{
    /** Verified, its counter spent, waiting for its customer (ApplyTap). */
    case Pending = 'pending';

    /** It gave its stamp. */
    case Stamped = 'stamped';

    /** Refused when received or when applied, with a TapRejection. */
    case Rejected = 'rejected';

    /** Nobody claimed it before it expired. */
    case Expired = 'expired';
}
