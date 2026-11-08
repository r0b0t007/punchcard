<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the owner who asked (CHW-49): their export couldn't be made; they can ask again. Queued, in their language. */
final class CustomerExportFailedMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $businessName) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $notifiable instanceof User ? $notifiable->firstName() : null;

        return (new MailMessage)
            ->subject(__('Your export of :business couldn\'t be made', ['business' => $this->businessName]))
            ->greeting($name === null ? __('Hello!') : __('Hello :name,', ['name' => Plain::text($name)]))
            ->line(__('We couldn\'t make your export of :business\'s customers and stamps. Please ask for it again from the Customers page.', ['business' => Plain::text($this->businessName)]));
    }
}
