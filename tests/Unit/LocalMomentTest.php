<?php

declare(strict_types=1);

use App\Support\LocalMoment;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Calendar days in a site's time (CHW-44)
|--------------------------------------------------------------------------
|
| "Today", "Yesterday", "3 days ago": counted in days on the site's calendar,
| whatever the hours between, a clock change's 23 or 25 hour day included.
|
*/

it('counts calendar days since a moment in the site\'s time', function (string $at, string $now, string $timezone, int $days): void {
    expect(LocalMoment::daysSince(CarbonImmutable::parse($at), $timezone, CarbonImmutable::parse($now)))->toBe($days);
})->with([
    'an hour before, the same day' => ['2026-11-01 08:00:00', '2026-11-01 09:00:00', 'Asia/Dubai', 0],
    'an hour before, across midnight there' => ['2026-10-31 19:30:00', '2026-10-31 20:30:00', 'Asia/Dubai', 1],
    'across Paris leaving summer time' => ['2026-10-24 22:30:00', '2026-10-26 22:30:00', 'Europe/Paris', 1],
    'three days' => ['2026-10-29 10:00:00', '2026-11-01 09:00:00', 'Asia/Dubai', 3],
]);
