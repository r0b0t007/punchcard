<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\CampaignSegment;
use App\Jobs\PushCampaign;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\User;
use App\Support\LocalMoment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * An owner's campaign (CHW-42, B9), sent now or scheduled for later:
 *
 * - Once per composer: the same idempotency key (a double tap on Send, a
 *   retried post) returns the campaign already made, never a second one,
 *   before anything is counted.
 * - To somebody: an audience with nobody on a phone with notifications on is
 *   refused, and uses none of the week's campaigns.
 * - Within the business's limit (CampaignLimit) at the moment it goes,
 *   counted with the business locked, so two quick sends never pass it
 *   together. The lock is short and lets taps through (no key update: their
 *   foreign key checks don't wait); who could get it is counted before it is
 *   taken (a scheduled one reaches its audience as it is then).
 * - Now: delivered through the queue (PushCampaign), in the business's
 *   tenant (QueuedTenant). Later: the scheduler queues it when it is due
 *   (DispatchDueCampaigns).
 *
 * Run by whoever runs the business, in their tenant (the model's guards
 * check it). The card it opens is checked by StoreCampaignRequest.
 *
 * @throws CampaignRefused
 */
final readonly class CreateCampaign
{
    public function __construct(private CampaignLimit $limit, private CampaignAudience $audience) {}

    public function handle(Business $business, User $by, string $idempotencyKey, CampaignSegment $segment, string $title, string $body, ?int $cardId, ?CarbonInterface $sendAt = null): Campaign
    {
        $made = $this->made($business, $idempotencyKey);

        if ($made instanceof Campaign) {
            return $made;
        }

        $reach = DescribeAudience::reach($this->audience->handle($business)[$segment->value]);

        if ($reach === 0) {
            throw CampaignRefused::nobody();
        }

        $sendAt ??= now();
        $campaign = DB::transaction(function () use ($business, $by, $idempotencyKey, $segment, $title, $body, $cardId, $reach, $sendAt): Campaign {
            Business::query()->whereKey($business->id)->lock('for no key update')->first();

            // Posted twice at once: the second waited for the lock.
            $made = $this->made($business, $idempotencyKey);

            if ($made instanceof Campaign) {
                return $made;
            }

            $limit = $this->limit->handle($business, $sendAt);

            if ($limit['used'] >= $limit['max']) {
                throw CampaignRefused::limit($limit['max'], $limit['nextAt'] ?? now(), LocalMoment::timezoneOf($business->firstLocation()->first()));
            }

            $campaign = (new Campaign)->forceFill([
                'business_id' => $business->id,
                'created_by' => $by->id,
                'idempotency_key' => $idempotencyKey,
                'title' => $title,
                'body' => $body,
                'card_id' => $cardId,
                'segment' => $segment,
                'send_at' => $sendAt,
                'audience_count' => $reach,
            ]);
            $campaign->save();

            return $campaign;
        });

        if ($campaign->wasRecentlyCreated && ! $campaign->send_at->isFuture()) {
            PushCampaign::dispatch($campaign->id);
        }

        return $campaign;
    }

    private function made(Business $business, string $idempotencyKey): ?Campaign
    {
        return Campaign::query()->where('business_id', $business->id)->where('idempotency_key', $idempotencyKey)->first();
    }
}
