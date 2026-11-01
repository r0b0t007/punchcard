<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Business;
use App\Models\Campaign;
use App\Support\LocalMoment;

/**
 * The Campaigns page (CHW-42, design 12c): the business's latest campaigns,
 * newest first, each with when the owner sent it (or when it goes) in the
 * business's time, and who it went to: the followers in its audience with a
 * phone, the number the owner was told on sending (the pushes accepted stay
 * for results, B10). One due but not yet picked up by the queue reads as
 * sending. And how many of the week's campaigns are left (CampaignLimit).
 * Read in the owner's tenant.
 */
final readonly class DescribeCampaigns
{
    public const int SHOWN = 30;

    public function __construct(private CampaignLimit $limit) {}

    /**
     * @return array{
     *     campaigns: list<array{id: int, title: string, segment: string, status: string, at: array{day: 'today'|'other', date: string, time: string}, people: int}>,
     *     left: int,
     *     max: int,
     * }
     */
    public function handle(Business $business): array
    {
        $timezone = LocalMoment::timezoneOf($business->firstLocation()->first());
        $limit = $this->limit->handle($business, now());

        return [
            'campaigns' => array_values(Campaign::query()->where('business_id', $business->id)
                ->latest('send_at')->latest('id')->limit(self::SHOWN)->get()
                ->map(fn (Campaign $campaign): array => [
                    'id' => $campaign->id,
                    'title' => $campaign->title,
                    'segment' => $campaign->segment->value,
                    'status' => ($campaign->status === CampaignStatus::Scheduled && $campaign->send_at->lessThanOrEqualTo(now()) ? CampaignStatus::Sending : $campaign->status)->value,
                    'at' => LocalMoment::of($campaign->send_at, $timezone),
                    'people' => $campaign->audience_count,
                ])->all()),
            'left' => $limit['left'],
            'max' => $limit['max'],
        ];
    }
}
