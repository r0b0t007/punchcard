<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Admin\Concerns\ChangesBusinessStatus;
use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Support\Tenancy\TenantContext;

/**
 * The platform admin verifies a pending business once reviewed (CHW-34, A1).
 * Verification gates nothing (a pending business works, as decided for
 * CHW-22): it records that the platform checked it, and where a reinstated
 * business returns to. Recorded in the audit log.
 */
final readonly class VerifyBusiness
{
    use ChangesBusinessStatus;

    public function __construct(private TenantContext $context, private RecordAudit $recordAudit) {}

    public function handle(Business $business): Business
    {
        return $this->transition(
            $business,
            function (Business $locked): void {
                if ($locked->status !== BusinessStatus::Pending) {
                    throw new BusinessStatusRefused(__(':business is not waiting for verification (it is :status).', [
                        'business' => $locked->name,
                        'status' => $locked->status->getLabel(),
                    ]));
                }
            },
            fn (): array => ['status' => BusinessStatus::Verified, 'verified_at' => now()],
            'business.verified',
        );
    }
}
