<?php

declare(strict_types=1);

namespace App\Support\Automations;

/**
 * A birthday bonus's idempotency key (CHW-46): one per card and year, the
 * year of that birthday in the giving business's time. GiveBirthdayStamps
 * writes it; the message reads it back, here and as it is sent. A partial
 * unique index holds it once per card across the organization
 * (stamp_events_birthday_once).
 */
final class BirthdayKey
{
    public static function for(int $enrollmentId, int $year): string
    {
        return 'birthday:'.$enrollmentId.':'.$year;
    }

    /** The birthday message's occasion for the year: one per automation and customer (automation_sends). */
    public static function occasion(int $year): string
    {
        return 'birthday:'.$year;
    }

    /** A LIKE pattern matching every card's key for the year. */
    public static function ofYear(int $year): string
    {
        return 'birthday:%:'.$year;
    }
}
