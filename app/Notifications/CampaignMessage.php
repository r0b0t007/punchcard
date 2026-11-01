<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A business's campaign on a follower's phone (CHW-42, design 12d): titled
 * with the business's name, then the owner's (CampaignText), the owner's
 * message below, both as written: never translated, so the direction is the
 * text's own. Tapping it opens their enrollment of the card chosen, or My
 * cards. Sent by DeliverCampaign.
 */
class CampaignMessage extends Notification
{
    public function __construct(
        public readonly int $campaignId,
        public readonly string $title,
        public readonly string $body,
        public readonly ?int $enrollmentId,
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
            ->data(['url' => $this->enrollmentId === null ? route('app.cards', [], false) : route('app.cards.show', $this->enrollmentId, false)]);
    }
}
