<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Campaigns\SendCampaignBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * One batch of a campaign's delivery (CHW-42), queued by DeliverCampaign in
 * the business's tenant (QueuedTenant). Short, inside the queue's
 * retry_after (config/queue.php), so no second worker takes it while it
 * runs. Tried again when cut short (CHW-48): each follower is claimed
 * before their push (CampaignSend), so a retry skips who was pushed and
 * reaches the rest; a push that failed let its claim go, and is tried again.
 */
#[Tries(3)]
#[Backoff(30)]
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
