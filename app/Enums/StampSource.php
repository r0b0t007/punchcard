<?php

declare(strict_types=1);

namespace App\Enums;

/** How a stamp event came about (stamp-flow skill). */
enum StampSource: string
{
    /** A verified SUN tap on a stamper: the tag and counter are recorded. */
    case Nfc = 'nfc';

    /** Staff scanned the customer's member QR. */
    case Qr = 'qr';

    /** Staff or owner added stamps by hand, with a reason. */
    case Manual = 'manual';

    case Bonus = 'bonus';

    case Birthday = 'birthday';

    case Referral = 'referral';

    /** A correction of earlier events (qty may be negative), with a reason. */
    case Correction = 'correction';
}
