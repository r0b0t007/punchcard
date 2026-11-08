<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\BusinessMember;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;

/** A business's customers (CHW-44, B4, B5): whoever runs the business, never its staff. */
final class BusinessCustomerPolicy
{
    use ReadsTenant;

    public function viewAny(User $user, Business $business): bool
    {
        return $this->runs($business->organization_id, $business->id);
    }

    public function view(User $user, BusinessCustomer $customer): bool
    {
        return $this->runs($customer->organization_id, $customer->business_id);
    }

    /**
     * Their customers and stamps as CSV (CHW-49): the business's owner alone, working in it. Not its staff, nor the
     * organization's admin: as few people as possible take customers out of the app (decided).
     */
    public function export(User $user, Business $business): bool
    {
        return $this->tenant()->businessId() === $business->id && $this->tenant()->businessRole() === BusinessRole::Owner;
    }

    /**
     * Downloading an export from its mailed link (CHW-49), whichever business the session is in: the link is for this
     * user, who still owns the business, and the business still operates (not suspended, not closed). Read in bypass,
     * as the link opens outside the business's tenant.
     */
    public function downloadExport(User $user, int $businessId, int $for): bool
    {
        return $for === $user->id && $this->tenant()->bypass(fn (): bool => Business::query()->operating()->whereKey($businessId)->exists()
            && BusinessMember::query()->where('business_id', $businessId)->where('user_id', $user->id)->where('role', BusinessRole::Owner)->exists());
    }
}
