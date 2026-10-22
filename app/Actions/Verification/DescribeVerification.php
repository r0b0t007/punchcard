<?php

declare(strict_types=1);

namespace App\Actions\Verification;

use App\Enums\BusinessStatus;
use App\Enums\DocumentKind;
use App\Models\Business;
use App\Models\BusinessDocument;

/**
 * The "Verify your business" page (CHW-145, design 9a): where the business
 * stands, and each document kind's latest: sent, accepted or to send again
 * (with the platform's reason), when it was sent, and when the files go.
 * Never the file or where it is. Read in the business's tenant.
 */
final readonly class DescribeVerification
{
    /**
     * @return array{verified: bool, canSend: bool, documents: list<array{kind: string, status: string|null, sentAt: string|null, reason: string|null, deletesAt: string|null}>}
     */
    public function handle(Business $business): array
    {
        $latest = BusinessDocument::query()->where('business_id', $business->id)->orderByDesc('id')->get()
            ->unique(fn (BusinessDocument $document): string => $document->kind->value)
            ->keyBy(fn (BusinessDocument $document): string => $document->kind->value);

        return [
            'verified' => $business->status === BusinessStatus::Verified,
            'canSend' => $business->status === BusinessStatus::Pending,
            'documents' => array_map(function (DocumentKind $kind) use ($latest): array {
                $document = $latest->get($kind->value);

                return [
                    'kind' => $kind->value,
                    'status' => $document?->status->value,
                    'sentAt' => $document?->created_at?->toIso8601String(),
                    'reason' => $document?->rejection_reason,
                    'deletesAt' => $document?->deletes_at?->toIso8601String(),
                ];
            }, DocumentKind::cases()),
        ];
    }
}
