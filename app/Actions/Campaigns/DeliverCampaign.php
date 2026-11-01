<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Jobs\PushCampaignBatch;
use App\Models\Business;
use App\Models\Campaign;
use App\Support\Tenancy\TenantContext;

/**
 * A campaign's delivery (CHW-42), from its queued job (PushCampaign):
 *
 * - Once, and on time: it is claimed (scheduled to sending) while due and
 *   less than DispatchDueCampaigns::LATE_MINUTES late, so a job run again, a
 *   canceled campaign, or one a backlogged queue held past its hour, sends
 *   nothing (the scheduler then cancels it).
 * - To its audience as it is now (CampaignAudience): someone who stopped
 *   following meanwhile gets nothing. Those of them with a phone
 *   (DescribeAudience::withPhone, nobody without the app's VAPID key) are
 *   handed out in batches of BATCH, each its own short job
 *   (PushCampaignBatch, SendCampaignBatch); each push a phone's push service
 *   accepts is counted (CountCampaignPush).
 * - Then sent: handed out. A delivery failing midway is sent too, with what
 *   it handed out (PushCampaign::failed()).
 *
 * In bypass(): its status is its delivery's, and the reads are kept to the
 * campaign's business and organization. The batches are queued in the
 * business's tenant, the one this job runs in (QueuedTenant).
 */
final readonly class DeliverCampaign
{
    /** Small: each push waits on a phone's push service (config/webpush.php client timeout), all inside the batch job's timeout. */
    public const int BATCH = 10;

    public function __construct(private TenantContext $context, private CampaignAudience $audience) {}

    public function handle(int $campaignId): void
    {
        $this->context->bypass(function () use ($campaignId): void {
            $claimed = Campaign::query()->whereKey($campaignId)
                ->where('status', CampaignStatus::Scheduled)
                ->where('send_at', '<=', now())
                ->where('send_at', '>', now()->subMinutes(DispatchDueCampaigns::LATE_MINUTES))
                ->update(['status' => CampaignStatus::Sending]);

            if ($claimed !== 1) {
                return;
            }

            $campaign = Campaign::query()->findOrFail($campaignId);
            $business = Business::query()->whereKey($campaign->business_id)->where('organization_id', $campaign->organization_id)->firstOrFail();

            foreach (array_chunk(DescribeAudience::withPhone($this->audience->handle($business)[$campaign->segment->value]), self::BATCH) as $batch) {
                PushCampaignBatch::dispatch($campaignId, $batch);
            }

            Campaign::query()->whereKey($campaignId)->update(['status' => CampaignStatus::Sent, 'sent_at' => now()]);
        });
    }
}
