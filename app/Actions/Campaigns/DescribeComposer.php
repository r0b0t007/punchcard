<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Models\Business;
use App\Models\LoyaltyCard;
use App\Support\Campaigns\CampaignText;
use App\Support\LocalMoment;

/**
 * What the composer needs (CHW-42, design 12a):
 *
 * - business: its name, which titles the notification (CampaignText);
 * - segments: each audience's followers and who will get it on a phone
 *   (DescribeAudience);
 * - cards: what tapping it may open, the business's active cards;
 * - left, max, nextAt: the week's campaigns left, and when the next may go
 *   once none is (CampaignLimit), in the business's time;
 * - titleRoom, bodyMax: how long the title may be beside the name, and the
 *   message.
 *
 * Read in the owner's tenant.
 */
final readonly class DescribeComposer
{
    public function __construct(private DescribeAudience $describeAudience, private CampaignLimit $limit) {}

    /**
     * @return array{
     *     business: string,
     *     segments: array<string, array{followers: int, reach: int}>,
     *     cards: list<array{id: int, name: string}>,
     *     left: int,
     *     max: int,
     *     nextAt: array{day: 'today'|'other', date: string, time: string}|null,
     *     titleRoom: int,
     *     bodyMax: int,
     * }
     */
    public function handle(Business $business): array
    {
        $limit = $this->limit->handle($business, now());

        return [
            'business' => $business->name,
            'segments' => $this->describeAudience->handle($business),
            'cards' => array_values(LoyaltyCard::query()->honouredBy($business->id)->where('active', true)->orderBy('id')->get(['id', 'name'])
                ->map(fn (LoyaltyCard $card): array => ['id' => $card->id, 'name' => $card->name])->all()),
            'left' => max(0, $limit['max'] - $limit['used']),
            'max' => $limit['max'],
            'nextAt' => $limit['nextAt'] === null ? null : LocalMoment::of($limit['nextAt'], LocalMoment::timezoneOf($business->firstLocation()->first())),
            'titleRoom' => CampaignText::titleRoom($business->name),
            'bodyMax' => CampaignText::BODY,
        ];
    }
}
