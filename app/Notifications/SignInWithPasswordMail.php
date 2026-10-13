<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * In place of a sign-in link, for an account that may not use one
 * (MaySignInByLink, CHW-23): it signs in with its password or a passkey.
 * Sent all the same, so the page never says which accounts exist. An
 * account signed up by link that turned on two-factor never chose a
 * password: the mail says where to choose one (only its owner reads it).
 */
final class SignInWithPasswordMail extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Signing in to punchcard'))
            ->line(__('Someone asked for a sign-in link for this address. Your account signs in with its password or a passkey, so we didn\'t send one.'))
            ->action(__('Sign in'), route('login'))
            ->line(__('Never chose a password? Choose one at :url', ['url' => route('password.request')]))
            ->line(__('If it wasn\'t you, you can ignore this email.'));
    }
}
