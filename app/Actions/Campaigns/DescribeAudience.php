<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\CampaignSegment;
use App\Models\Business;
use App\Models\User;
use App\Support\Push\PushKey;

/**
 * The composer's audiences (CHW-42, design 12a): for each segment, the
 * followers in it (CampaignAudience) and who among them will get it, on a
 * phone with notifications on. Nobody without the app's VAPID key.
 */
final readonly class DescribeAudience
{
    public function __construct(private CampaignAudience $audience) {}

    /** @return array<value-of<CampaignSegment>, array{followers: int, reach: int}> */
    public function handle(Business $business): array
    {
        $segments = $this->audience->handle($business);
        // Every segment is part of All: who has a phone is read once.
        $withPhone = array_flip(self::withPhone($segments[CampaignSegment::All->value]));

        return array_map(fn (array $ids): array => [
            'followers' => count($ids),
            'reach' => count(array_intersect_key(array_flip($ids), $withPhone)),
        ], $segments);
    }

    /**
     * How many of them have a phone with notifications on.
     *
     * @param  list<int>  $ids
     */
    public static function reach(array $ids): int
    {
        return count(self::withPhone($ids));
    }

    /**
     * Those of them with a phone with notifications on, by id; nobody without
     * the app's VAPID key. Who a campaign is counted for, and sent to
     * (DeliverCampaign).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function withPhone(array $ids): array
    {
        if (PushKey::public() === null) {
            return [];
        }

        $found = [];

        // In chunks: a big organization's followers would pass a database's bound parameters.
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (User::query()->whereKey($chunk)->whereHas('pushSubscriptions')->orderBy('id')->pluck('id') as $id) {
                $found[] = (int) $id;
            }
        }

        return $found;
    }
}
