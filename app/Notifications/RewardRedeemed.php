<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A reward staff redeemed with a scan of the customer's QR, on their phones
 * (CHW-228, design 14a): titled with the place, the reward below and what to
 * do if it wasn't them, in the customer's language (User::preferredLocale),
 * opening the card (C6). Tagged per reward. Sent by PushScanRedemption; once a
 * phone's push service accepts it, My cards no longer shows its notice
 * (MarkRedeemNoticeSent).
 */
final class RewardRedeemed extends Notification
{
    public function __construct(
        public readonly int $rewardId,
        private readonly int $enrollmentId,
        private readonly string $place,
        private readonly string $rewardText,
    ) {}

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $locale = app()->getLocale();

        return (new WebPushMessage)
            ->title($this->place)
            ->body(__('Your :reward was redeemed at the counter. Not you? Tell the staff.', ['reward' => $this->rewardText]))
            ->icon('/icons/icon-192.png')
            ->tag('redeemed-'.$this->rewardId)
            ->lang($locale)
            ->dir($locale === 'ar' ? 'rtl' : 'ltr')
            ->data(['url' => route('app.cards.show', $this->enrollmentId, false)]);
    }
}
