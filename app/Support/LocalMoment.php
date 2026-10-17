<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Location;
use Carbon\CarbonInterface;

/**
 * A moment as a customer screen shows it, in the location's time: today or
 * another day (a result page can be opened again later), the date and the
 * time. Stored times are UTC (CLAUDE.md); this is the one place they turn
 * local for the tap and reward screens.
 */
final class LocalMoment
{
    /** The timezone a location's moments are shown in: its own, else the app's (a tap or reward with no site). */
    public static function timezoneOf(?Location $location): string
    {
        return $location->timezone ?? (string) config('app.timezone');
    }

    /**
     * @param  CarbonInterface|null  $now  the moment "today" is told against: the clock, unless the caller reads at another moment
     * @return array{day: 'today'|'other', date: string, time: string}
     */
    public static function of(CarbonInterface $at, string $timezone, ?CarbonInterface $now = null): array
    {
        $local = $at->copy()->setTimezone($timezone);

        return [
            'day' => $local->isSameDay(($now ?? now())->copy()->setTimezone($timezone)) ? 'today' : 'other',
            'date' => $local->format('Y-m-d'),
            'time' => $local->format('H:i'),
        ];
    }

    /**
     * When something refused now becomes possible (the next stamp after a cooldown or today's limit), in the
     * location's time: now (it has passed since), today, tomorrow or a later date (a cooldown can be a day or more).
     *
     * @return array{day: 'now'|'today'|'tomorrow'|'later', date: string, time: string}|null
     */
    public static function next(?CarbonInterface $at, string $timezone): ?array
    {
        if (! $at instanceof CarbonInterface) {
            return null;
        }

        $local = $at->copy()->setTimezone($timezone);
        $days = (int) now($timezone)->startOfDay()->diffInDays($local->copy()->startOfDay());

        return [
            'day' => match (true) {
                $local->isPast() => 'now',
                $days <= 0 => 'today',
                $days === 1 => 'tomorrow',
                default => 'later',
            },
            'date' => $local->format('Y-m-d'),
            'time' => $local->format('H:i'),
        ];
    }
}
