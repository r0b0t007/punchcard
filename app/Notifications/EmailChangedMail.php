<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To an account's old address, once it was changed in settings (CHW-23): if
 * it wasn't them, they hear of it there.
 */
final class EmailChangedMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $newEmail) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your punchcard address changed'))
            ->line(__('The address of your punchcard account was changed to :email.', ['email' => $this->newEmail]))
            ->line(__('If it wasn\'t you, reply to this email at once.'));
    }
}
