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
        $segments = [];

        foreach (CampaignSegment::cases() as $segment) {
            $ids = $this->audience->handle($business, $segment);
            $segments[$segment->value] = ['followers' => count($ids), 'reach' => self::reach($ids)];
        }

        return $segments;
    }

    /**
     * Those of them with a phone with notifications on.
     *
     * @param  list<int>  $ids
     */
    public static function reach(array $ids): int
    {
        if (PushKey::public() === null) {
            return 0;
        }

        // In chunks: a big organization's followers would pass a database's bound parameters.
        return array_sum(array_map(fn (array $chunk): int => User::query()->whereKey($chunk)->whereHas('pushSubscriptions')->count(), array_chunk($ids, 1000)));
    }
}
