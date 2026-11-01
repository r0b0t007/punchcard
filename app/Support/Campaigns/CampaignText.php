<?php

declare(strict_types=1);

namespace App\Support\Campaigns;

/**
 * A campaign's words (CHW-42, design 12d): the notification's title is the
 * business's name, then the owner's title, in TITLE characters in all, so a
 * customer always knows who writes. The title keeps at least TITLE_MIN of
 * them; a longer name is cut to fit. The message is BODY at most, what a lock
 * screen shows. The composer counts the same way (lib/campaigns.ts).
 */
final class CampaignText
{
    public const int TITLE = 40;

    public const int TITLE_MIN = 15;

    public const int BODY = 120;

    private const string BETWEEN = ' · ';

    /** How long the owner's title may be beside this business's name. */
    public static function titleRoom(string $business): int
    {
        return max(self::TITLE_MIN, self::TITLE - mb_strlen(self::BETWEEN) - mb_strlen($business));
    }

    public static function notificationTitle(string $business, string $title): string
    {
        $room = self::TITLE - mb_strlen(self::BETWEEN) - mb_strlen($title);
        $name = mb_strlen($business) > $room ? mb_substr($business, 0, max(0, $room - 1)).'…' : $business;

        return $name.self::BETWEEN.$title;
    }
}
