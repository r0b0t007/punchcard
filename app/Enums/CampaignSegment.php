<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who a campaign goes to (CHW-42, B9), among the business's followers: the
 * organization's followers who came in, with a presence stamp at this
 * business (CampaignAudience). "Birthday this week" comes with the birthday
 * field (CHW-46).
 */
enum CampaignSegment: string
{
    /** Every follower who came in. */
    case All = 'all';

    /** Going quiet at this business now (CHW-45): the Customers page's rule (CustomerSegment::Quiet). */
    case GoingQuiet = 'going_quiet';

    /** The TOP followers with the most presence stamps here in TOP_DAYS days. */
    case Top = 'top_10';

    /**
     * No presence stamp here for QUIET_DAYS days or more. No longer offered
     * (GoingQuiet took its place, CHW-45): kept for the campaigns made with
     * it, listed under its name and, when scheduled, sent to it.
     */
    case Quiet = 'quiet_14d';

    public const int QUIET_DAYS = 14;

    public const int TOP = 10;

    public const int TOP_DAYS = 90;

    /**
     * The audiences a new campaign may go to, in the composer's order.
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        return [self::All, self::GoingQuiet, self::Top];
    }
}
