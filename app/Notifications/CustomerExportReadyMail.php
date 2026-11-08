<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Support\Auth\MailedLink;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;
use LogicException;

/**
 * To the owner who asked (CHW-49, design 21): their export of a business's customers and stamps is ready. The
 * business, the days and the counts, and one button: a link signed for a day (LINK_HOURS), for them alone, signed in
 * (CustomerExportController::download). Queued, in their language.
 */
final class CustomerExportReadyMail extends Notification implements ShouldQueue
{
    use Queueable;

    public const int LINK_HOURS = 24;

    public function __construct(
        public readonly int $businessId,
        public readonly string $businessName,
        public readonly string $export,
        public readonly string $from,
        public readonly string $to,
        public readonly int $customers,
        public readonly int $stamps,
        public readonly CarbonImmutable $madeAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** The download link: for this owner alone, until a day after the export was made. */
    public function link(User $owner): string
    {
        return MailedLink::signed('customers.export.download', $this->madeAt->addHours(self::LINK_HOURS), [
            'business' => $this->businessId, 'export' => $this->export, 'for' => $owner->id, 'from' => $this->from, 'to' => $this->to,
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (! $notifiable instanceof User) {
            throw new LogicException('An export is mailed to the owner who asked for it.');
        }

        // Sent in the owner's language (HasLocalePreference), dates and numbers too.
        $locale = app()->getLocale();
        $day = fn (string $date): string => CarbonImmutable::parse($date)->translatedFormat('j M Y');
        $name = $notifiable->firstName();

        return (new MailMessage)
            ->subject(__('Your export of :business is ready', ['business' => $this->businessName]))
            ->greeting($name === null ? __('Hello!') : __('Hello :name,', ['name' => Plain::text($name)]))
            ->line(__('Your export of :business\'s customers and stamps from :from to :to is ready: :customers customers and :stamps stamps.', [
                'business' => Plain::text($this->businessName),
                'from' => $day($this->from),
                'to' => $day($this->to),
                'customers' => Number::format($this->customers, locale: $locale),
                'stamps' => Number::format($this->stamps, locale: $locale),
            ]))
            ->action(__('Download the export'), $this->link($notifiable))
            ->line(__('The link works for 24 hours, and only when you\'re signed in to punchcard. You asked for this export on the Customers page; if it wasn\'t you, change your password.'));
    }
}
