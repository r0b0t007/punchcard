<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\DocumentKind;
use App\Models\User;
use App\Support\Auth\MailedLink;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To a business's owners (CHW-145, Punchcard Emails): the platform admin
 * couldn't verify it with the documents it sent (RequestNewDocuments).
 * Which ones, the admin's reason quoted as typed (on one line, its brackets
 * escaped: Plain, isolated from the line's direction), the verification page, and the day the unusable ones go
 * at the latest, at the business's time. Queued, in each owner's language.
 * Its button opens the business it names (CHW-295, MailedLink::toBusiness).
 */
final class NewDocumentsNeededMail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<DocumentKind>  $kinds
     */
    public function __construct(
        public readonly int $businessId,
        public readonly string $businessName,
        public readonly array $kinds,
        public readonly string $reason,
        public readonly CarbonImmutable $deletesAt,
        public readonly string $timezone,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $several = count($this->kinds) > 1;
        $names = ['business' => Plain::text($this->businessName)];
        $date = $this->deletesAt->setTimezone($this->timezone)->translatedFormat('j F');
        $name = $notifiable instanceof User ? $notifiable->firstName() : null;

        return (new MailMessage)
            ->subject($several
                ? __('We need new documents to verify :business', ['business' => $this->businessName])
                : __('We need a new document to verify :business', ['business' => $this->businessName]))
            ->greeting($name === null ? __('Hello!') : __('Hello :name,', ['name' => Plain::text($name)]))
            ->line(match (true) {
                $several => __('We couldn\'t verify :business with the ID and the proof of address you sent. Please send new ones.', $names),
                $this->kinds === [DocumentKind::IdDocument] => __('We couldn\'t verify :business with the ID you sent. Please send a new one.', $names),
                default => __('We couldn\'t verify :business with the proof of address you sent. Please send a new one.', $names),
            })
            // Isolated (FSI, PDI): a reason in French or English reads whole inside an Arabic line.
            ->line(__('Our reason: “:reason”', ['reason' => "\u{2068}".Plain::text((string) preg_replace('/\s+/u', ' ', $this->reason))."\u{2069}"]))
            ->action($several ? __('Send new documents') : __('Send a new document'), MailedLink::toBusiness($this->businessId, 'verification', $notifiable))
            ->line($several
                ? __(':business keeps working while we check. The documents we couldn\'t use are deleted as soon as you send new ones, or on :date at the latest.', [...$names, 'date' => $date])
                : __(':business keeps working while we check. The document we couldn\'t use is deleted as soon as you send a new one, or on :date at the latest.', [...$names, 'date' => $date]));
    }
}
