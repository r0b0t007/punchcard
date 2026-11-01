<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Jobs\PushCampaignBatch;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\User;
use App\Support\Push\PushKey;
use App\Support\Tenancy\TenantContext;

/**
 * A campaign's delivery (CHW-42), from its queued job (PushCampaign):
 *
 * - Once: it is claimed (scheduled to sending) while due, so a job run again,
 *   or a canceled campaign, sends nothing.
 * - To its audience as it is now (CampaignAudience): someone who stopped
 *   following meanwhile gets nothing. Those of them with a phone are handed
 *   out in batches of BATCH, each its own short job (PushCampaignBatch,
 *   SendCampaignBatch), which adds how many got it.
 * - Then sent: handed out. Nobody without the app's VAPID key.
 *
 * In bypass(): its status is its delivery's, and the reads are kept to the
 * campaign's business and organization. The batches are queued in the
 * business's tenant, the one this job runs in (QueuedTenant).
 */
final readonly class DeliverCampaign
{
    public const int BATCH = 50;

    public function __construct(private TenantContext $context, private CampaignAudience $audience) {}

    public function handle(int $campaignId): void
    {
        $this->context->bypass(function () use ($campaignId): void {
            $claimed = Campaign::query()->whereKey($campaignId)
                ->where('status', CampaignStatus::Scheduled)
                ->where('send_at', '<=', now())
                ->update(['status' => CampaignStatus::Sending]);

            if ($claimed !== 1) {
                return;
            }

            if (PushKey::public() !== null) {
                $campaign = Campaign::query()->findOrFail($campaignId);
                $business = Business::query()->whereKey($campaign->business_id)->where('organization_id', $campaign->organization_id)->firstOrFail();

                foreach (array_chunk($this->audience->handle($business)[$campaign->segment->value], 1000) as $chunk) {
                    $withPhone = User::query()->whereKey($chunk)->whereHas('pushSubscriptions')->orderBy('id')->pluck('id')->all();

                    foreach (array_chunk($withPhone, self::BATCH) as $batch) {
                        PushCampaignBatch::dispatch($campaignId, $batch);
                    }
                }
            }

            Campaign::query()->whereKey($campaignId)->update(['status' => CampaignStatus::Sent, 'sent_at' => now()]);
        });
    }
}
