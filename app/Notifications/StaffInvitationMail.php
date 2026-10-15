<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\StaffInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An invitation to a business's staff (CHW-146, InviteStaff): its signed
 * link, good for StaffInvitation::DAYS days, once. The business and location
 * names are the owner's own text, read as plain text: the mail's Markdown
 * never turns them into a link or formatting, so nobody can send a link of
 * their own from punchcard's address.
 */
final class StaffInvitationMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly string $businessName,
        public readonly ?string $locationName,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $business = $this->plain($this->businessName);

        return (new MailMessage)
            ->subject(__('Join :business on punchcard', ['business' => $this->businessName]))
            ->line($this->locationName === null
                ? __(':business invites you to join its staff on punchcard.', ['business' => $business])
                : __(':business invites you to join its staff at :location on punchcard.', ['business' => $business, 'location' => $this->plain($this->locationName)]))
            ->action(__('See the invitation'), $this->url)
            ->line(__('The link works for :days days. If you weren\'t expecting it, you can ignore this email.', ['days' => StaffInvitation::DAYS]));
    }

    /** Text with every Markdown character escaped, so it renders as written (a URL in it never becomes a link). */
    private function plain(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~:\/])/', '\\\\$1', $text);
    }
}
