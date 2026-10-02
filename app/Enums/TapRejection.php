<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a stamper tap was refused. Each rejected tap is recorded with one of
 * these for the owner's fraud view, so the values are stable identifiers.
 */
enum TapRejection: string
{
    /** The tap URL is not a well-formed SUN message. */
    case Malformed = 'malformed';

    /** No stamper is registered for the tag UID. */
    case UnknownTag = 'unknown_tag';

    /** The CMAC does not match: the URL was forged or altered. */
    case BadMac = 'bad_mac';

    /** The read counter is not above the stamper's last one: a copied or reused URL. */
    case Replay = 'replay';

    /** The stamper was disabled (lost or stolen). */
    case StamperDisabled = 'stamper_disabled';

    /** The customer was stamped on this card too recently. */
    case Cooldown = 'cooldown';

    /** The customer reached today's stamp limit on this card. */
    case DailyCap = 'daily_cap';
}
