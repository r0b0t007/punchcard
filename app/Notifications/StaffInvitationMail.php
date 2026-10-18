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
 * names are the owner's own text: their brackets are escaped (Plain).
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
        $business = Plain::text($this->businessName);

        return (new MailMessage)
            ->subject(__('Join :business on punchcard', ['business' => $this->businessName]))
            ->line($this->locationName === null
                ? __(':business invites you to join its staff on punchcard.', ['business' => $business])
                : __(':business invites you to join its staff at :location on punchcard.', ['business' => $business, 'location' => Plain::text($this->locationName)]))
            ->action(__('See the invitation'), $this->url)
            ->line(__('The link works for :days days. If you weren\'t expecting it, you can ignore this email.', ['days' => StaffInvitation::DAYS]));
    }
}
