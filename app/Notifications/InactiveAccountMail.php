<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To a customer whose account has gone unused for almost the inactivity
 * limit (CHW-58, WarnInactiveAccounts): it will be deleted on a given day,
 * with any reward not used, unless they sign in before then. In their
 * language. Sent while the warning is written, not queued, so the warning
 * stands only for an email that went out.
 */
final class InactiveAccountMail extends Notification
{
    public function __construct(public readonly CarbonImmutable $deleteOn) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $notifiable instanceof User ? $notifiable->firstName() : null;
        $date = $this->deleteOn->translatedFormat('j F Y');

        return (new MailMessage)
            ->subject(__('Your punchcard account will be deleted on :date', ['date' => $date]))
            ->greeting($name === null ? __('Hello!') : __('Hello :name,', ['name' => Plain::text($name)]))
            ->line(__('You haven\'t used your punchcard account in a long time. To keep no more data than we need, we will delete it on :date, with your cards and any reward you haven\'t used.', ['date' => $date]))
            ->action(__('Sign in to keep it'), route('login'))
            ->line(__('Signing in before then keeps your account, your stamps and your rewards. If you\'d rather it go, there\'s nothing to do.'));
    }
}
