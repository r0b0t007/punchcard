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
     * @return array{day: 'today'|'other', date: string, time: string}
     */
    public static function of(CarbonInterface $at, string $timezone): array
    {
        $local = $at->copy()->setTimezone($timezone);

        return [
            'day' => $local->isSameDay(now($timezone)) ? 'today' : 'other',
            'date' => $local->format('Y-m-d'),
            'time' => $local->format('H:i'),
        ];
    }
}
