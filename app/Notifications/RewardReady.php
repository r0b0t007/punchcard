<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Your reward is ready", on the customer's phones (CHW-41, design 11d):
 * titled with the place, the reward below, in the customer's language
 * (User::preferredLocale), opening the card (C6). Tagged per reward, so a
 * phone shows it once. Sent by PushRewardsReady.
 */
final class RewardReady extends Notification
{
    public function __construct(
        private readonly int $rewardId,
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
            ->body(__('Your reward is ready: :reward', ['reward' => $this->rewardText]))
            ->icon('/icons/icon-192.png')
            ->tag('reward-'.$this->rewardId)
            ->lang($locale)
            ->dir($locale === 'ar' ? 'rtl' : 'ltr')
            ->data(['url' => route('app.cards.show', $this->enrollmentId, false)]);
    }
}
