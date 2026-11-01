<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use Carbon\CarbonInterface;
use RuntimeException;

/** A campaign can't go as asked; the message says why, in the owner's words. */
final class CampaignRefused extends RuntimeException
{
    /** The business sent its campaigns for these 7 days (CampaignLimit); when the next may go, in its time. */
    public static function limit(int $max, CarbonInterface $nextAt, string $timezone): self
    {
        return new self(__('You have sent :max campaigns in 7 days. The next one can go from :date.', [
            'max' => $max,
            'date' => $nextAt->copy()->setTimezone($timezone)->translatedFormat('l j F, H:i'),
        ]));
    }

    /** Nobody in the audience has a phone with notifications on: it would reach no one, and use one of the week's. */
    public static function nobody(): self
    {
        return new self(__('Nobody in this audience has notifications on a phone yet.'));
    }
}
