<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Admin\Concerns\ChangesBusinessStatus;
use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

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
        return $this->context->bypass(fn (): Business => DB::transaction(function () use ($business): Business {
            $locked = $this->lockOpen($business);

            if ($locked->status !== BusinessStatus::Suspended) {
                throw new BusinessStatusRefused(__(':business is not suspended.', ['business' => $locked->name]));
            }

            $locked->forceFill([
                'status' => $locked->verified_at !== null ? BusinessStatus::Verified : BusinessStatus::Pending,
                'suspended_at' => null,
            ])->save();
            $this->recordAudit->handle('business.reinstated', $locked);

            return $locked;
        }));
    }
}
