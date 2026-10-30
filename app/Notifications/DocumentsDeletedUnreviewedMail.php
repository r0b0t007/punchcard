<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Actions\Tenancy\SelectBusiness;
use App\Enums\DocumentKind;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To a business's owners (CHW-58, Punchcard Emails): the nightly sweep
 * deleted the verification documents it sent more than 12 months ago and
 * the platform never decided on (DeleteExpiredDocuments). Which ones, and
 * the verification page to send them again. Only to a business that can
 * still send; queued, in each owner's language.
 * Its button opens the business it names (CHW-295, SelectBusiness::link).
 */
final class DocumentsDeletedUnreviewedMail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<DocumentKind>  $kinds
     */
    public function __construct(
        public readonly int $businessId,
        public readonly string $businessName,
        public readonly array $kinds,
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
        $name = $notifiable instanceof User ? $notifiable->firstName() : null;
        $sent = [...$names, 'months' => (int) config('punchcard.documents.waiting_months')];

        return (new MailMessage)
            ->subject($several
                ? __('We deleted the documents :business sent for verification', ['business' => $this->businessName])
                : __('We deleted the document :business sent for verification', ['business' => $this->businessName]))
            ->greeting($name === null ? __('Hello!') : __('Hello :name,', ['name' => Plain::text($name)]))
            ->line(match (true) {
                $several => __('You sent us the ID and the proof of address of :business more than :months months ago, and we never finished checking them. We don\'t keep verification documents longer than that, so we deleted them.', $sent),
                $this->kinds === [DocumentKind::IdDocument] => __('You sent us the owner\'s ID for :business more than :months months ago, and we never finished checking it. We don\'t keep verification documents longer than that, so we deleted it.', $sent),
                default => __('You sent us the proof of address of :business more than :months months ago, and we never finished checking it. We don\'t keep verification documents longer than that, so we deleted it.', $sent),
            })
            ->action($several ? __('Send them again') : __('Send it again'), SelectBusiness::link($this->businessId, 'verification', $notifiable))
            ->line($several
                ? __(':business keeps working meanwhile. Send them again whenever you want the business verified.', $names)
                : __(':business keeps working meanwhile. Send it again whenever you want the business verified.', $names));
    }
}
