<?php

declare(strict_types=1);

namespace App\Actions\Counter;

/** Why a staff member can't scan a code (ScanMemberQr), or stamp or redeem with a scan now (StampScan, RedeemScan, CHW-30). */
enum ScanRefusal: string
{
    /** Its time ran out (CounterScan::SECONDS): scan the code again. */
    case Expired = 'expired';
    /** It already gave its stamp, or redeemed its one reward. */
    case Used = 'used';
    /** Another staff member scanned it, or the one who did no longer works at its location. */
    case NotYours = 'not_yours';

    /** A staff member scanned their own member QR: a colleague serves them, as an arming never pays whoever made it. */
    case OwnCode = 'own_code';
}
