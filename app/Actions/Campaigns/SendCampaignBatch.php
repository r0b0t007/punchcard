<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\User;
use App\Notifications\CampaignMessage;
use App\Support\Campaigns\CampaignText;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One batch of a campaign's delivery (CHW-42), from its queued job
 * (PushCampaignBatch): each of these followers with a phone gets the
 * notification (CampaignMessage). Each is claimed first (CHW-48,
 * CampaignSend), once per campaign and follower, so a batch run again never
 * pushes twice, and the campaign's results know who it went to. One failing
 * never stops the others; logged by id only. Each push a phone's push
 * service accepts is marked and counted as it goes (CountCampaignPush), so a
 * batch cut short keeps what it sent.
 *
 * In bypass(): the reads and claims are kept to the campaign's business and
 * organization.
 */
final readonly class SendCampaignBatch
{
    public function __construct(private TenantContext $context) {}

    /** @param list<int> $userIds handed out by DeliverCampaign, from the campaign's audience */
    public function handle(int $campaignId, array $userIds): void
    {
        $this->context->bypass(function () use ($campaignId, $userIds): void {
            $campaign = Campaign::query()->findOrFail($campaignId);
            $business = Business::query()->whereKey($campaign->business_id)->where('organization_id', $campaign->organization_id)->firstOrFail();
            $title = CampaignText::notificationTitle($business->name, $campaign->title);

            foreach (User::query()->whereKey($userIds)->get() as $user) {
                try {
                    $send = CampaignSend::query()->createOrFirst(['campaign_id' => $campaign->id, 'user_id' => $user->id], ['business_id' => $campaign->business_id]);

                    // Claimed already: this batch ran before, and pushed it then.
                    if (! $send->wasRecentlyCreated) {
                        continue;
                    }

                    $user->notifyNow(new CampaignMessage($campaign->id, $send->id, $title, $campaign->body));
                } catch (Throwable) {
                    Log::warning('A campaign notification failed.', ['campaign' => $campaign->id, 'user' => $user->id]);
                }
            }
        });
    }
}
