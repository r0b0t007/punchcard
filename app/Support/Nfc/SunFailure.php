<?php

declare(strict_types=1);

namespace App\Support\Nfc;

/**
 * Why a SUN URL failed verification. The values match the tap rejection
 * reasons recorded for the owner's fraud view.
 */
enum SunFailure: string
{
    case Malformed = 'malformed';
    case BadMac = 'bad_mac';
}
