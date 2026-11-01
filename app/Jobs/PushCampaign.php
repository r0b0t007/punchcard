<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Campaigns\DeliverCampaign;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * A campaign's delivery (CHW-42), queued in its business's tenant
 * (QueuedTenant), so the owner's request never waits on push services; it
 * hands the audience out in batches (DeliverCampaign). One try, short, inside
 * the queue's retry_after (config/queue.php). Failed before it went (its
 * business closed meanwhile, say), the campaign is canceled: it neither
 * holds one of the week's campaigns nor goes out late.
 */
#[Tries(1)]
#[Timeout(60)]
final class PushCampaign implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $campaignId) {}

    public function handle(DeliverCampaign $deliverCampaign): void
    {
        $deliverCampaign->handle($this->campaignId);
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->bypass(fn () => Campaign::query()->whereKey($this->campaignId)
            ->where('status', CampaignStatus::Scheduled)
            ->update(['status' => CampaignStatus::Canceled]));
    }
}
