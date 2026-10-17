<?php

declare(strict_types=1);

namespace App\Actions\MemberQr;

/** Why a scanned member QR does not count (VerifyMemberQr, CHW-28). */
enum MemberQrRefusal: string
{
    /** Not a member QR at all (another code, a link). */
    case Malformed = 'malformed';

    /** Shaped like one, but its signature fails: changed or made up. */
    case Forged = 'forged';

    /** Older than the previous window, or from one still to come: a screenshot, a clock gone wrong. */
    case Expired = 'expired';

    /** This code was already used for this card while it lasts. */
    case Replayed = 'replayed';

    /** Too many wrong short codes typed for this member lately: only their QR counts for now (MemberQrScan::fromCode). */
    case TooManyAttempts = 'too_many_attempts';

    /** The customer's account is gone (deleted since). */
    case UnknownUser = 'unknown_user';
}
