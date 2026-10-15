<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LoginLink;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Confirm it's you" for a signed-in account without a password (CHW-23,
 * ConfirmByEmailController): a link that confirms, good once, for
 * LoginLink::MINUTES. To the account's own address only.
 */
final class ConfirmLinkMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Confirm it\'s you'))
            ->line(__('Tap the button to confirm it\'s you, in the punchcard settings you opened. The link works once, for :minutes minutes.', ['minutes' => LoginLink::MINUTES]))
            ->action(__('Confirm'), $this->url)
            ->line(__('If you didn\'t ask for it, ignore this email: nothing changes without the link.'));
    }
}
