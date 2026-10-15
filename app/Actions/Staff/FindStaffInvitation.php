<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\StaffInvitation;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantContext;

/**
 * An invitation its link may still use (CHW-146), with its business and
 * location: pending (unused, in date, not replaced) and to a business and
 * location still open. Read in bypass(): whoever opens the link is not on
 * that business's staff yet. The caller has checked the link's signature.
 */
final readonly class FindStaffInvitation
{
    public function __construct(private TenantContext $context) {}

    public function handle(int $id): ?StaffInvitation
    {
        return $this->context->bypass(function () use ($id): ?StaffInvitation {
            $invitation = StaffInvitation::query()->whereKey($id)->pending()->with(['business', 'location'])->first();

            return $invitation instanceof StaffInvitation && ArchivedSites::isOpen($invitation->business_id, $invitation->location_id)
                ? $invitation
                : null;
        });
    }
}
