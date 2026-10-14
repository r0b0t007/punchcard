<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LoginLink;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email sign-in link (CHW-23, SendLoginLink): signed, good once, for
 * LoginLink::MINUTES. To a customer's account, or to a new address.
 */
final class LoginLinkMail extends Notification
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
            ->subject(__('Your punchcard sign-in link'))
            ->line(__('Tap the button to sign in to punchcard. The link works once, for :minutes minutes.', ['minutes' => LoginLink::MINUTES]))
            ->action(__('Sign in'), $this->url)
            ->line(__('If you didn\'t ask for it, ignore this email: nobody can sign in without the link.'));
    }
}
