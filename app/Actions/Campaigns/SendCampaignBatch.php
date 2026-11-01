<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Models\Business;
use App\Models\Campaign;
use App\Models\CardEnrollment;
use App\Models\User;
use App\Notifications\CampaignMessage;
use App\Support\Campaigns\CampaignText;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One batch of a campaign's delivery (CHW-42), from its queued job
 * (PushCampaignBatch): each of these followers with a phone gets the
 * notification (CampaignMessage), opening their own enrollment of the card
 * chosen, or My cards. One failing never stops the others; logged by id
 * only. Each push a phone's push service accepts is counted as it goes
 * (CountCampaignPush), so a batch cut short keeps what it sent.
 *
 * In bypass(): the reads are kept to the campaign's business and
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
            $enrollments = $campaign->card_id === null ? [] : CardEnrollment::query()
                ->where('organization_id', $campaign->organization_id)
                ->where('card_id', $campaign->card_id)
                ->whereIn('user_id', $userIds)
                ->pluck('id', 'user_id')->all();
            $title = CampaignText::notificationTitle($business->name, $campaign->title);

            foreach (User::query()->whereKey($userIds)->get() as $user) {
                try {
                    $user->notifyNow(new CampaignMessage($campaign->id, $title, $campaign->body, $enrollments[$user->id] ?? null));
                } catch (Throwable) {
                    Log::warning('A campaign notification failed.', ['campaign' => $campaign->id, 'user' => $user->id]);
                }
            }
        });
    }
}
