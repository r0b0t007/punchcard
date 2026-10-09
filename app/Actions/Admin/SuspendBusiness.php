<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Admin\Concerns\ChangesBusinessStatus;
use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The platform admin suspends a business, with a reason (CHW-34, A1): its
 * taps, stamps and redemptions stop and its people lose access (ApplyTap,
 * ArchivedSites with counter, Business::operating), until it is reinstated.
 * Its stampers stay assigned, unlike an archive.
 *
 * Locks the business's current stampers, then the business row, as a tap
 * does (the stamper, then the business, read unlocked): a tap being applied
 * finishes first, and any later one sees the suspension. Recorded in the
 * audit log with the reason.
 */
final readonly class SuspendBusiness
{
    use ChangesBusinessStatus;

    public function __construct(private TenantContext $context, private RecordAudit $recordAudit) {}

    public function handle(Business $business, string $reason): Business
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new BusinessStatusRefused(__('Give a reason for the suspension.'));
        }

        return $this->context->bypass(fn (): Business => DB::transaction(function () use ($business, $reason): Business {
            Stamper::query()->current()->where('business_id', $business->id)->orderBy('id')->lockForUpdate()->get();
            $locked = $this->lockOpen($business);

            if ($locked->status === BusinessStatus::Suspended) {
                throw new BusinessStatusRefused(__(':business is already suspended.', ['business' => $locked->name]));
            }

            $locked->forceFill(['status' => BusinessStatus::Suspended, 'suspended_at' => now()])->save();
            $this->recordAudit->handle('business.suspended', $locked, $reason);

            return $locked;
        }));
    }
}
