<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A business's campaign on a follower's phone (CHW-42, design 12d): titled
 * with the business's name, then the owner's (CampaignText), the owner's
 * message below, both as written: never translated, so the direction is the
 * text's own. Tapping it goes through its open link (CHW-48,
 * CampaignOpenController), which counts the open for the campaign's results
 * and opens their enrollment of the card chosen, or My cards. The link is
 * relative (the phone opens it on this app only, sw-push.ts) and signed for
 * good: a notification can wait days to be tapped. Sent by
 * SendCampaignBatch, for the follower's claimed send.
 */
class CampaignMessage extends Notification
{
    public function __construct(
        public readonly int $campaignId,
        public readonly int $sendId,
        public readonly string $title,
        public readonly string $body,
    ) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/icons/icon-192.png')
            ->tag('campaign-'.$this->campaignId)
            ->dir('auto')
            ->data(['url' => URL::signedRoute('campaigns.opened', ['send' => $this->sendId], absolute: false)]);
    }
}
