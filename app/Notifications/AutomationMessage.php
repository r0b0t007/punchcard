<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AutomationTrigger;
use App\Support\Automations\AutomationText;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A business's automation message on a customer's phone (CHW-46): ours, in
 * the customer's language (User::preferredLocale), or the owner's, as
 * written (titled with the business's name, CampaignText, in the text's own
 * direction). Opens the card at that business, or the card holding the
 * reward. Tagged per trigger and business, so a phone keeps one of each.
 * Sent by SendAutomationMessages; its send is marked sent once a phone's
 * push service accepts it (MarkAutomationSent).
 */
final class AutomationMessage extends Notification
{
    public function __construct(
        public readonly int $sendId,
        public readonly AutomationTrigger $trigger,
        public readonly int $businessId,
        public readonly string $business,
        public readonly ?string $title,
        public readonly ?string $body,
        public readonly ?int $enrollmentId,
        public readonly ?string $reward = null,
        public readonly ?int $stamps = null,
    ) {}

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $locale = app()->getLocale();
        $owners = $this->title !== null || $this->body !== null;

        return (new WebPushMessage)
            ->title(AutomationText::title($this->trigger, $this->business, $this->title))
            ->body(AutomationText::body($this->trigger, $this->body, $this->reward, $this->stamps))
            ->icon('/icons/icon-192.png')
            ->tag('automation-'.$this->trigger->value.'-'.$this->businessId)
            ->lang($locale)
            ->dir($owners ? 'auto' : ($locale === 'ar' ? 'rtl' : 'ltr'))
            ->data(['url' => $this->enrollmentId === null ? route('app.cards', [], false) : route('app.cards.show', $this->enrollmentId, false)]);
    }
}
