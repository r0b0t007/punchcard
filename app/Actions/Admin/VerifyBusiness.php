<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Admin\Concerns\ChangesBusinessStatus;
use App\Actions\Verification\DeleteExpiredDocuments;
use App\Enums\BusinessStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Support\Tenancy\TenantContext;

/**
 * The platform admin verifies a pending business once reviewed (CHW-34, A1).
 * Verification gates nothing (a pending business works, as decided for
 * CHW-22): it records that the platform checked it, and where a reinstated
 * business returns to. Recorded in the audit log. Its verification
 * documents still waiting are accepted with it, and kept until a set time
 * after (CHW-145, punchcard.documents.retention_months).
 *
 * Only the documents the admin saw (CHW-294): the caller passes the ids of
 * the waiting documents its page showed, none where it showed none, and it
 * is refused, nothing changed, unless they are exactly the ones waiting
 * under the business's row lock (as SubmitDocument and the nightly cleanup
 * take it). One sent, replaced or deleted since is never accepted unseen,
 * and a business is never verified on documents gone meanwhile. The audit
 * entry names the documents accepted. Whether any are needed stays as
 * decided for CHW-34: none.
 */
final readonly class VerifyBusiness
{
    use ChangesBusinessStatus;

    public function __construct(private TenantContext $context, private RecordAudit $recordAudit) {}

    /** @param  list<int>  $documentIds  the waiting documents the admin's page showed */
    public function handle(Business $business, array $documentIds): Business
    {
        $seen = array_unique($documentIds);
        sort($seen);

        return $this->transition(
            $business,
            function (Business $locked) use ($seen): void {
                if ($locked->status !== BusinessStatus::Pending) {
                    throw new BusinessStatusRefused(__(':business is not waiting for verification (it is :status).', [
                        'business' => $locked->name,
                        'status' => $locked->status->getLabel(),
                    ]));
                }

                if (BusinessDocument::query()->where('business_id', $locked->id)->waiting()->orderBy('id')->pluck('id')->all() !== $seen) {
                    throw new BusinessStatusRefused(__('These documents changed since you opened this page. Reload it and look again.'));
                }
            },
            fn (): array => ['status' => BusinessStatus::Verified, 'verified_at' => now()],
            'business.verified',
            afterSave: function () use ($seen): void {
                BusinessDocument::query()->whereKey($seen)->update([
                    'status' => DocumentStatus::Accepted,
                    'decided_at' => now(),
                    'deletes_at' => DeleteExpiredDocuments::deletesAt(now()),
                ]);
            },
            audit: fn (): array => $seen === [] ? [] : [
                'document_ids' => $seen,
                'kinds' => BusinessDocument::query()->whereKey($seen)->orderBy('id')->pluck('kind')
                    ->map(fn (DocumentKind $kind): string => $kind->value)->all(),
            ],
        );
    }
}
