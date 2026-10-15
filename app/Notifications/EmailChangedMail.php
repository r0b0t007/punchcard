<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To an account's old address, once it was changed (CHW-23, ApplyEmailChange):
 * if it wasn't them, they hear of it there. On the queue.
 */
final class EmailChangedMail extends Notification implements ShouldQueue
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
