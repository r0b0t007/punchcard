<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a campaign is (CHW-42): scheduled until its delivery claims it
 * (sending), then sent. A scheduled one may be canceled until then.
 */
enum CampaignStatus: string
{
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Canceled = 'canceled';
}
