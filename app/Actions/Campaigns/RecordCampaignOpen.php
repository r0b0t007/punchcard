<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\CardEnrollment;
use App\Support\Tenancy\TenantContext;

/**
 * A tap on a campaign's notification (CHW-48, B10): their send is opened,
 * once, and the campaign's open count goes up only then, so tapping it
 * again never counts twice. Then where the notification opens, worked out
 * here, never taken from the link: their enrollment of the card chosen, or
 * My cards. The send is gone (the account deleted since): My cards, nothing
 * counted. In bypass(): the follower may be signed out, and the counts are
 * the campaign's.
 */
final readonly class RecordCampaignOpen
{
    public function __construct(private TenantContext $context) {}

    /** @return string the page to open, relative */
    public function handle(int $sendId): string
    {
        return $this->context->bypass(function () use ($sendId): string {
            $send = CampaignSend::query()->with('campaign:id,organization_id,card_id')->find($sendId);

            if (! $send instanceof CampaignSend) {
                return route('app.cards', [], false);
            }

            if ($send->markOpened(now())) {
                Campaign::query()->whereKey($send->campaign_id)->increment('open_count');
            }

            $enrollment = $send->campaign->card_id === null ? null : CardEnrollment::query()
                ->where('organization_id', $send->campaign->organization_id)
                ->where('card_id', $send->campaign->card_id)
                ->where('user_id', $send->user_id)
                ->value('id');

            return $enrollment === null ? route('app.cards', [], false) : route('app.cards.show', $enrollment, false);
        });
    }
}
