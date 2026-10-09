<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Admin\Concerns\ChangesBusinessStatus;
use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Support\Tenancy\TenantContext;

/**
 * The platform admin suspends a business, with a reason (CHW-34, A1): its
 * taps, stamps and redemptions stop and its people lose access (ReceiveTap,
 * ApplyTap, ArchivedSites with counter, Business::operating), until it is
 * reinstated. Its stampers stay assigned, unlike an archive.
 *
 * Locks the business's current stampers, the business row, then the current
 * stampers again: the business lock waits for a stamper being registered or
 * moved there (it share-locks the business), so the second pass holds that
 * one too. Every tap path locks its stamper first, so each tap either commits
 * before the suspension or sees it, without locking the business itself.
 * Recorded in the audit log with the reason.
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

        return $this->transition(
            $business,
            function (Business $locked): void {
                if ($locked->status === BusinessStatus::Suspended) {
                    throw new BusinessStatusRefused(__(':business is already suspended.', ['business' => $locked->name]));
                }
            },
            fn (): array => ['status' => BusinessStatus::Suspended, 'suspended_at' => now()],
            'business.suspended',
            $reason,
            lockStampers: true,
        );
    }
}
