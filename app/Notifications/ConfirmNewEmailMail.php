<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Actions\Auth\RequestEmailChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To a new address asked for in settings (CHW-23, RequestEmailChange): its
 * link switches the account to it. On the queue: a mail server's hiccup
 * never fails the settings page.
 */
final class ConfirmNewEmailMail extends Notification implements ShouldQueue
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
            ->subject(__('Confirm your new punchcard address'))
            ->line(__('Tap the button to make this the address of your punchcard account. The link works once, for :minutes minutes.', ['minutes' => RequestEmailChange::MINUTES]))
            ->action(__('Use this address'), $this->url)
            ->line(__('If you didn\'t ask for it, ignore this email: the account keeps its address.'));
    }
}
