<?php

declare(strict_types=1);

namespace App\Support\Nfc;

use InvalidArgumentException;

/**
 * A tag uid as nfc_tags stores it: the 7-byte NTAG 424 DNA uid in 14
 * uppercase hex digits, starting with the NXP manufacturer byte 04. Readers
 * and spreadsheets print it in either case, separated by spaces (with /u, \s
 * takes non-breaking ones too), tabs, colons or hyphens; this reads all of
 * those, and nothing else (a stray letter is an error, never dropped).
 */
final class TagUid
{
    /** The uid's length in bytes: SunVerifier reads it from a tap, KeyDiversifier derives keys from it. */
    public const int BYTES = 7;

    private const string NXP = '04';

    /** @throws InvalidArgumentException with a message fit to show the admin */
    public static function normalise(string $typed): string
    {
        $uid = strtoupper((string) preg_replace('/[\s:\-]+/u', '', $typed));

        if (strlen($uid) !== self::BYTES * 2 || ! ctype_xdigit($uid)) {
            throw new InvalidArgumentException(__('A tag uid is :digits hex digits (:bytes bytes), as a reader prints it, for example 04A1B2C3D4E5F6.', ['digits' => self::BYTES * 2, 'bytes' => self::BYTES]));
        }

        if (! str_starts_with($uid, self::NXP)) {
            throw new InvalidArgumentException(__('An NTAG 424 DNA uid must start with :prefix, the NXP manufacturer byte: check the reading.', ['prefix' => self::NXP]));
        }

        return $uid;
    }
}
