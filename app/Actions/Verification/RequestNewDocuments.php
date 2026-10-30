<?php

declare(strict_types=1);

namespace App\Actions\Verification;

use App\Actions\Admin\RecordAudit;
use App\Enums\BusinessStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Notifications\NewDocumentsNeededMail;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The platform admin asks a business for new verification documents
 * (CHW-145, "Ask for new documents" in A1): the documents the admin looked
 * at, by id, become rejected with the reason, decided now and dated for
 * deletion (DeleteExpiredDocuments::deletesAt), in the audit log
 * ("documents.rejected", which ones: the reason stays on the documents,
 * deleted with them, never in the log kept for good). The business stays
 * pending; its owners are emailed once this commits, each in their
 * language, and see the reason on their verification page. A new upload
 * replaces a rejected one (SubmitDocument).
 *
 * Under the business's row lock, as SubmitDocument and VerifyBusiness take
 * it: refused unless it is pending and operating, and unless every chosen
 * document is still its own and waiting: one replaced since the page was
 * read is never rejected unseen. Who may is the caller's
 * (BusinessPolicy::verify, the platform admin's). Runs in bypass().
 */
final readonly class RequestNewDocuments
{
    public function __construct(private TenantContext $context, private RecordAudit $recordAudit) {}

    /**
     * @param  list<int>  $documentIds
     * @return Collection<int, BusinessDocument> the documents rejected
     */
    public function handle(Business $business, array $documentIds, string $reason): Collection
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DocumentRefused(__('Say why: the owners read it.'));
        }

        if ($documentIds === []) {
            throw new DocumentRefused(__('None of these documents is waiting for review.'));
        }

        $deletesAt = DeleteExpiredDocuments::deletesAt(now());

        [$rejected, $owners, $timezone, $name] = $this->context->bypass(fn (): array => DB::transaction(function () use ($business, $documentIds, $reason, $deletesAt): array {
            $locked = Business::query()->whereKey($business->id)->operating()->lock('for no key update')->first();

            if (! $locked instanceof Business || $locked->status !== BusinessStatus::Pending) {
                throw new DocumentRefused(__('Only a business waiting to be verified can be asked for new documents.'));
            }

            $documents = BusinessDocument::query()->where('business_id', $locked->id)->waiting()
                ->whereKey($documentIds)->orderBy('id')->get();

            if ($documents->count() !== count(array_unique($documentIds))) {
                // Replaced by the owner, or decided by someone else, since the admin's page was read.
                throw new DocumentRefused(__('These documents changed since you opened this page. Reload it and look again.'));
            }

            $documents->each(fn (BusinessDocument $document): bool => $document->forceFill([
                'status' => DocumentStatus::Rejected,
                'rejection_reason' => $reason,
                'decided_at' => now(),
                'deletes_at' => $deletesAt,
            ])->save());

            $this->recordAudit->handle('documents.rejected', $locked, context: [
                'document_ids' => $documents->pluck('id')->all(),
                'kinds' => $documents->map(fn (BusinessDocument $document): string => $document->kind->value)->all(),
            ]);

            return [$documents, $locked->owners()->get(), LocalMoment::timezoneOf($locked->firstLocation()->first()), $locked->name];
        }));

        Notification::send($owners, new NewDocumentsNeededMail(
            businessId: $business->id,
            businessName: $name,
            kinds: array_values($rejected->map(fn (BusinessDocument $document): DocumentKind => $document->kind)->all()),
            reason: $reason,
            deletesAt: $deletesAt,
            timezone: $timezone,
        ));

        return $rejected;
    }
}
