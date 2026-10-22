<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To a customer whose account has gone unused for almost the inactivity
 * limit (CHW-58, WarnInactiveAccounts; design B, date first): the day it
 * will be deleted first, then why and what goes with it, and that signing
 * in once keeps it. In their language. Sent while the warning is written,
 * not queued, so the warning stands only for an email that went out.
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
            ->line('**'.__('Your punchcard account will be deleted on :date.', ['date' => $date]).'**')
            ->line(__('You haven\'t signed in or collected a stamp in almost two years, and we don\'t keep data we no longer need. Your cards, your stamps and any reward you haven\'t used will go with it.'))
            ->action(__('Keep my account'), route('login'))
            ->line(__('Signing in once before then is enough. If you\'d rather it go, there\'s nothing to do.'));
    }
}
