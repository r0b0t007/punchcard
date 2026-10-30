<?php

declare(strict_types=1);

namespace App\Actions\Verification;

use App\Enums\DocumentKind;
use App\Http\Requests\Verification\DocumentRequest;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Support\LocalMoment;

/**
 * The "Verify your business" page (CHW-145, design 9a): where the business
 * stands, and each document kind's latest: sent, accepted or to send again
 * (with the platform's reason), when it was sent, and when the files go.
 * Never the file or where it is. Read in the business's tenant.
 */
final readonly class DescribeVerification
{
    public function __construct(private SubmitDocument $submitDocument) {}

    /**
     * @return array{verified: bool, canSend: bool, maxMegabytes: int, retentionMonths: int, waitingMonths: int, documents: list<array{kind: string, status: string|null, sentOn: string|null, reason: string|null, deletesOn: string|null}>}
     */
    public function handle(Business $business): array
    {
        $latest = BusinessDocument::latestByKind($business);
        // Dates in the business's own time (its first site's), never the device's.
        $timezone = LocalMoment::timezoneOf($business->firstLocation()->first());

        return [
            // Sent back with each upload (DocumentRequest): the business this page shows.
            'businessId' => $business->id,
            'verified' => $business->verified_at !== null,
            'canSend' => $this->submitDocument->maySend($business),
            'maxMegabytes' => intdiv(DocumentRequest::MAX_KILOBYTES, 1024),
            'retentionMonths' => (int) config('punchcard.documents.retention_months'),
            'waitingMonths' => (int) config('punchcard.documents.waiting_months'),
            'documents' => array_map(function (DocumentKind $kind) use ($latest, $timezone): array {
                $document = $latest->get($kind->value);

                return [
                    'kind' => $kind->value,
                    'status' => $document?->status->value,
                    'sentOn' => $document?->created_at?->setTimezone($timezone)->toDateString(),
                    'reason' => $document?->rejection_reason,
                    'deletesOn' => $document?->deletes_at?->setTimezone($timezone)->toDateString(),
                ];
            }, DocumentKind::cases()),
        ];
    }
}
