<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Campaign;
use App\Notifications\CampaignMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\Events\NotificationSent;
use Minishlink\WebPush\MessageSentReport;

/**
 * Counts a campaign's notification (CHW-42) once a phone's push service
 * accepted it: the web push channel reports each of the follower's phones,
 * and an expired or refused one is not a follower reached. Run as each push
 * goes (SendCampaignBatch), so a batch cut short keeps its count. The count is
 * its delivery's, in bypass().
 */
final readonly class CountCampaignPush
{
    public function __construct(private TenantContext $context) {}

    public function handle(NotificationSent $event): void
    {
        if (! $event->notification instanceof CampaignMessage || ! is_array($event->response)) {
            return;
        }

        $accepted = array_filter($event->response, fn (mixed $report): bool => $report instanceof MessageSentReport && $report->isSuccess());

        if ($accepted !== []) {
            $this->context->bypass(fn () => Campaign::query()->whereKey($event->notification->campaignId)->increment('sent_count'));
        }
    }
}
