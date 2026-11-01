<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\CampaignSegment;
use App\Jobs\PushCampaign;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\User;
use App\Support\LocalMoment;
use Illuminate\Support\Facades\DB;

/**
 * An owner's campaign (CHW-42, B9), sent now:
 *
 * - Once per composer: the same idempotency key (a double tap on Send, a
 *   retried post) returns the campaign already made, never a second one,
 *   before anything is counted.
 * - To somebody: an audience with nobody on a phone with notifications on is
 *   refused, and uses none of the week's campaigns.
 * - Within the business's limit (CampaignLimit), counted with the business
 *   locked, so two quick sends never pass it together. The lock is short
 *   and lets taps through (no key update: their foreign key checks don't
 *   wait); who could get it is counted before it is taken.
 * - Then delivered through the queue (PushCampaign), in the business's
 *   tenant (QueuedTenant).
 *
 * Run by whoever runs the business, in their tenant (the model's guards
 * check it). The card it opens is checked by StoreCampaignRequest.
 *
 * @throws CampaignRefused
 */
final readonly class CreateCampaign
{
    public function __construct(private CampaignLimit $limit, private CampaignAudience $audience) {}

    public function handle(Business $business, User $by, string $idempotencyKey, CampaignSegment $segment, string $title, string $body, ?int $cardId): Campaign
    {
        $made = $this->made($business, $idempotencyKey);

        if ($made instanceof Campaign) {
            return $made;
        }

        $reach = DescribeAudience::reach($this->audience->handle($business)[$segment->value]);

        if ($reach === 0) {
            throw CampaignRefused::nobody();
        }

        $campaign = DB::transaction(function () use ($business, $by, $idempotencyKey, $segment, $title, $body, $cardId, $reach): Campaign {
            Business::query()->whereKey($business->id)->lock('for no key update')->first();

            // Posted twice at once: the second waited for the lock.
            $made = $this->made($business, $idempotencyKey);

            if ($made instanceof Campaign) {
                return $made;
            }

            $limit = $this->limit->handle($business, now());

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
                'send_at' => now(),
                'audience_count' => $reach,
            ]);
            $campaign->save();

            return $campaign;
        });

        if ($campaign->wasRecentlyCreated) {
            PushCampaign::dispatch($campaign->id);
        }

        return $campaign;
    }

    private function made(Business $business, string $idempotencyKey): ?Campaign
    {
        return Campaign::query()->where('business_id', $business->id)->where('idempotency_key', $idempotencyKey)->first();
    }
}
