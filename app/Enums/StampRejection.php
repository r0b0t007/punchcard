<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why AddStamps refused a stamp: a clean answer for the caller (the tap
 * screen, the staff scanner), never a write. /t records the ones a tap can
 * hit as TapRejection.
 */
enum StampRejection: string
{
    /** The card is switched off. */
    case CardInactive = 'card_inactive';

    /** The business does not honour the card. */
    case NotHonoured = 'not_honoured';

    /** The business, location or organization is archived (CHW-139). */
    case SiteClosed = 'site_closed';

    /** The stamper is paused, or no longer assigned to this business. */
    case StamperUnavailable = 'stamper_unavailable';

    /** The customer was stamped on this card too recently. */
    case Cooldown = 'cooldown';

    /** The customer reached today's stamp limit at this business. */
    case DailyCap = 'daily_cap';

    /** The idempotency key was already used for a different stamp. */
    case IdempotencyConflict = 'idempotency_conflict';

    /** The correction would take the card below zero stamps. */
    case CorrectionBelowZero = 'correction_below_zero';

    /** The correction would take back more stamps than this business gave. */
    case CorrectionExceedsGiven = 'correction_exceeds_given';
}
