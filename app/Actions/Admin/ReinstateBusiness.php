<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Admin\Concerns\ChangesBusinessStatus;
use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Support\Tenancy\TenantContext;

/**
 * The platform admin lifts a business's suspension (CHW-34, A1): it goes back
 * to verified if it had been verified, else to pending, and its counter and
 * people work again. Recorded in the audit log.
 */
final readonly class ReinstateBusiness
{
    use ChangesBusinessStatus;

    public function __construct(private TenantContext $context, private RecordAudit $recordAudit) {}

    public function handle(Business $business): Business
    {
        return $this->transition(
            $business,
            function (Business $locked): void {
                if ($locked->status !== BusinessStatus::Suspended) {
                    throw new BusinessStatusRefused(__(':business is not suspended.', ['business' => $locked->name]));
                }
            },
            fn (Business $locked): array => [
                'status' => $locked->verified_at !== null ? BusinessStatus::Verified : BusinessStatus::Pending,
                'suspended_at' => null,
            ],
            'business.reinstated',
        );
    }
}
