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

    /** No presence stamp here for QUIET_DAYS days or more. */
    case Quiet = 'quiet_14d';

    /** The TOP followers with the most presence stamps here in TOP_DAYS days. */
    case Top = 'top_10';

    public const int QUIET_DAYS = 14;

    public const int TOP = 10;

    public const int TOP_DAYS = 90;
}
