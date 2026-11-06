<?php

declare(strict_types=1);

namespace App\Support\Automations;

use Carbon\CarbonInterface;

/**
 * Whose birthday a local day is (CHW-46): its month and day, and 29
 * February on 28 February of a year without one. User::birthdayOn reads it
 * in a query, the send-time check of a birthday message (isOn) on one
 * customer.
 */
final class Birthday
{
    /** Whether a birthday (month and day, either may be unset) falls on the day. */
    public static function isOn(?int $month, ?int $day, CarbonInterface $date): bool
    {
        return $month === $date->month
            && ($day === $date->day || (self::isLeapDayStandIn($date) && $day === 29));
    }

    /** 28 February of a year without a 29th: where a 29 February birthday falls. */
    public static function isLeapDayStandIn(CarbonInterface $date): bool
    {
        return $date->month === 2 && $date->day === 28 && ! $date->isLeapYear();
    }
}
