<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Campaigns\SendCampaignBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * One batch of a campaign's delivery (CHW-42), queued by DeliverCampaign in
 * the business's tenant (QueuedTenant). Short, inside the queue's
 * retry_after (config/queue.php), so no second worker takes it while it
 * runs. One try: a retry could push to a phone twice.
 */
#[Tries(1)]
#[Timeout(60)]
final class PushCampaignBatch implements ShouldQueue
{
    use Queueable;

    /** @param list<int> $userIds */
    public function __construct(public readonly int $campaignId, public readonly array $userIds) {}

    public function handle(SendCampaignBatch $sendCampaignBatch): void
    {
        $sendCampaignBatch->handle($this->campaignId, $this->userIds);
    }
}
