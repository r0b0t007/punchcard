<?php

declare(strict_types=1);

namespace App\Support\Nfc;

use App\Actions\Stampers\StamperRefused;

/**
 * A tag uid as nfc_tags stores it: the 7-byte NTAG 424 DNA uid in 14
 * uppercase hex digits, starting with the NXP manufacturer byte 04. Readers
 * print it with spaces or colons, in either case; this reads all of those.
 */
final class TagUid
{
    public static function normalise(string $typed): string
    {
        $uid = strtoupper(str_replace([' ', ':', '-'], '', trim($typed)));

        if (preg_match('/^[0-9A-F]{14}$/', $uid) !== 1) {
            throw new StamperRefused('A tag uid is 14 hex digits (7 bytes), as a reader prints it, for example 04A1B2C3D4E5F6.');
        }

        if (! str_starts_with($uid, '04')) {
            throw new StamperRefused('An NTAG 424 DNA uid must start with 04, the NXP manufacturer byte: check the reading.');
        }

        return $uid;
    }
}
