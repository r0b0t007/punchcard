<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Support\Auth\MailedLink;
use App\Support\LocalMoment;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To a business's owner (CHW-36, design 5a): someone tapped one of its
 * switched-off stampers, today or on a given day, at the site's time. Sent
 * by AlertDisabledStamperTap, itself queued, in the owner's language. The
 * stamper and site names are the business's own text: their brackets are
 * escaped, as in StaffInvitationMail.
 * Its button opens the business it names (CHW-295, MailedLink::toBusiness).
 */
final class StamperTappedWhileOffMail extends Notification
{
    public function __construct(
        public readonly int $businessId,
        public readonly ?string $label,
        public readonly int $stamperId,
        public readonly string $site,
        public readonly CarbonImmutable $tappedAt,
        public readonly string $timezone,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $local = $this->tappedAt->setTimezone($this->timezone);
        // In the owner's language: toMail runs in their locale.
        $stamper = $this->label ?? __('Stamper :id', ['id' => $this->stamperId]);
        $names = ['stamper' => Plain::text($stamper), 'site' => Plain::text($this->site), 'time' => $local->format('H:i')];
        $name = $notifiable instanceof User ? $notifiable->firstName() : null;

        return (new MailMessage)
            ->subject(__(':stamper was tapped while switched off', ['stamper' => $stamper]))
            ->greeting($name === null ? __('Hello!') : __('Hello :name,', ['name' => Plain::text($name)]))
            ->line(LocalMoment::of($this->tappedAt, $this->timezone)['day'] === 'today'
                ? __('Someone tapped :stamper at :site today at :time, while it is switched off. The tap was refused: no stamp was given.', $names)
                : __('Someone tapped :stamper at :site on :date at :time, while it is switched off. The tap was refused: no stamp was given.', [...$names, 'date' => $local->translatedFormat('j F')]))
            ->action(__('See your stampers'), MailedLink::toBusiness($this->businessId, 'stampers', $notifiable))
            ->line(__('If it went missing, keep it off. If you found it, switch it on again from your stampers page. You get this email at most once an hour per stamper.'));
    }
}
