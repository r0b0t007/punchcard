<?php

declare(strict_types=1);

namespace App\Actions\Counter;

/** Why a staff member's scan can't stamp or redeem now (StampScan, RedeemScan, CHW-30). */
enum ScanRefusal: string
{
    /** Its time ran out (CounterScan::SECONDS): scan the code again. */
    case Expired = 'expired';
    /** It already gave its stamp. */
    case Used = 'used';
    /** Another staff member scanned it. */
    case NotYours = 'not_yours';
}
