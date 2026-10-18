<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Actions\Fraud\AlertCapStreak;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To a business's owner (CHW-36, design 5a): a customer reached a card's
 * daily limit there AlertCapStreak::DAYS (three) days in a row, which days,
 * and what to do. The customer's first name only (a customer the business served on
 * each of those days); the card and business names are the business's own
 * text, their brackets escaped, as in StaffInvitationMail.
 */
final class DailyCapStreakMail extends Notification
{
    /** @param  list<string>  $days  the site's local days, Y-m-d, oldest first */
    public function __construct(
        public readonly ?string $customer,
        public readonly string $card,
        public readonly string $business,
        public readonly array $days,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $customer = $this->customer ?? __('A customer');
        $name = $notifiable instanceof User ? $notifiable->firstName() : null;

        return (new MailMessage)
            ->subject(__(':customer reached the daily limit :days days in a row', ['customer' => $customer, 'days' => AlertCapStreak::DAYS]))
            ->greeting($name === null ? __('Hello!') : __('Hello :name,', ['name' => Plain::text($name)]))
            ->line(__(':customer reached the daily limit of :card at :business three days in a row: :dates.', [
                'customer' => Plain::text($customer),
                'card' => Plain::text($this->card),
                'business' => Plain::text($this->business),
                'dates' => $this->dates(),
            ]))
            ->action(__('See your stampers'), route('stampers.index'))
            ->line(__('It may be a loyal regular, or someone collecting stamps for others. If the limit is too high, lower it in the card\'s settings.'));
    }

    /** "15, 16 and 17 October", or with each month when they differ. */
    private function dates(): string
    {
        $days = array_map(fn (string $day): CarbonImmutable => CarbonImmutable::parse($day), $this->days);
        $oneMonth = count(array_unique(array_map(fn (CarbonImmutable $day): string => $day->format('Y-m'), $days))) === 1;
        $last = array_pop($days);
        $earlier = array_map(fn (CarbonImmutable $day): string => $day->translatedFormat($oneMonth ? 'j' : 'j F'), $days);

        return __(':days and :last', ['days' => implode(', ', $earlier), 'last' => $last?->translatedFormat('j F') ?? '']);
    }
}
