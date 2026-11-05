<?php

declare(strict_types=1);

namespace App\Support\Automations;

use App\Features\Automations;
use App\Models\Business;
use App\Support\LocalMoment;
use App\Support\Tenancy\ArchivedSites;
use Carbon\CarbonInterface;
use Laravel\Pennant\Feature;

/**
 * When a business's automation messages go out (CHW-46): read by the hourly
 * run as it claims them (RunAutomations) and again as each is sent
 * (SendAutomationMessages), so a queue running late never sends at night
 * or for a business stopped meanwhile.
 */
final class AutomationWindow
{
    public const int OPENS = 8;

    public const int CLOSES = 22;

    /** A claim not sent within this many minutes is not sent: its moment has passed. */
    public const int LATE_MINUTES = 60;

    /**
     * A claim never sent is let go after this long, so a later run claims
     * it again: past LATE_MINUTES and the job's timeout, no job can still be
     * sending it.
     */
    public const int RELEASE_MINUTES = self::LATE_MINUTES * 2;

    /**
     * Whether the business sends now: allowed automations, open and not
     * suspended, between OPENS and CLOSES in its own time. Outside any
     * transaction: Pennant's first read of a business inserts a row.
     */
    public static function sendsNow(Business $business, CarbonInterface $now): bool
    {
        $hour = $now->copy()->setTimezone(LocalMoment::timezoneOfBusiness($business->id))->hour;

        return $hour >= self::OPENS && $hour < self::CLOSES
            && ArchivedSites::isOpen($business->id, null, counter: true)
            && Feature::for($business)->active(Automations::class);
    }
}
