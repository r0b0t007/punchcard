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
 * least: the same birthday every year, 29 February to 28 February too,
 * never a birthday moved to collect another.
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
     * Where a card's bonus that is too recent for another on a local day starts: BONUS_GAP_DAYS - 1 days before, at
     * the middle of the night between AutomationWindow::CLOSES and OPENS (03:00), never midnight. Bonuses are only
     * given in the day, so in one timezone this is the local date; between a franchise's sites hours apart (up to
     * five), last year's bonus given late in the evening at a site behind still falls before it, and a birthday
     * moved by a day still after it.
     */
    public static function lastBonusWindowStart(CarbonImmutable $day): CarbonImmutable
    {
        $night = intdiv(AutomationWindow::CLOSES + AutomationWindow::OPENS + 24, 2) - 24;

        return $day->startOfDay()->subDays(self::BONUS_GAP_DAYS - 1)->addHours($night)->utc();
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
