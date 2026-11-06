<?php

declare(strict_types=1);

namespace App\Support\Automations;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Whose birthday a local day is (CHW-46): its month and day, and 29
 * February on 28 February of a year without one. User::birthdayOn reads it
 * in a query, the send-time check of a birthday message (isOn) on one
 * customer.
 *
 * And when a birthday earns a bonus (CHW-370): once it was given
 * ON_FILE_DAYS days before or earlier, by local date (set today, or moved to
 * today, it does not), and a card's bonuses BONUS_GAP_DAYS days apart at
 * least, each by the date it was given in its own site's time: the same
 * birthday every year, 29 February to 28 February too, from any site of a
 * franchise whatever its timezone, never a birthday moved to collect
 * another.
 */
final class Birthday
{
    /** Days after it is given that a birthday counts, as C12 says: given on 5 October, a 4 November one does, given on the 6th it doesn't. */
    public const int ON_FILE_DAYS = 30;

    /** Days between a card's bonuses, at least: 5 March to 5 March is 365, or 366 over a 29 February. */
    public const int BONUS_GAP_DAYS = 365;

    /** When a birthday given too late to count on a local day starts: the midnight starting the day ON_FILE_DAYS - 1 before, in UTC. */
    public static function givenTooLateFrom(CarbonImmutable $day): CarbonImmutable
    {
        return $day->startOfDay()->subDays(self::ON_FILE_DAYS - 1)->utc();
    }

    /**
     * Whether a bonus given at a moment, at a site in a timezone, is too recent for another on a local day: its date
     * there is less than BONUS_GAP_DAYS before the day's.
     */
    public static function bonusTooRecent(CarbonInterface $givenAt, string $timezone, CarbonImmutable $day): bool
    {
        return $givenAt->copy()->setTimezone($timezone)->toDateString() > $day->subDays(self::BONUS_GAP_DAYS)->toDateString();
    }

    /** Since when a card's bonuses may be too recent on a local day, whatever their sites' timezones: two days spare, in UTC. */
    public static function recentBonusesSince(CarbonImmutable $day): CarbonImmutable
    {
        return $day->startOfDay()->subDays(self::BONUS_GAP_DAYS + 1)->utc();
    }

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
