<?php

declare(strict_types=1);

namespace App\Actions\Stamps;

use App\Enums\BusinessRole;
use App\Enums\OrganizationRole;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\OrganizationMember;
use App\Support\Tenancy\TenantContext;

/**
 * Whether someone works at a site, now: a member of the business (staff at
 * their location, if limited to one; an owner anywhere in it, as
 * ResolveTenant reads the limit), or an org admin of its organization. Who
 * may give stamps there (AddStamps), and whose arming a tap may still use
 * (ReceiveTap, CHW-29). Read in bypass(): a tap comes from no tenant.
 */
final readonly class WorksAt
{
    public function __construct(private TenantContext $context) {}

    public function handle(int $userId, int $businessId, int $locationId): bool
    {
        return $this->context->bypass(fn (): bool => BusinessMember::query()
            ->where('business_id', $businessId)
            ->where('user_id', $userId)
            ->where(fn ($membership) => $membership->where('role', BusinessRole::Owner)->orWhereNull('location_id')->orWhere('location_id', $locationId))
            ->exists()
            || OrganizationMember::query()
                ->where('user_id', $userId)
                ->where('role', OrganizationRole::OrgAdmin)
                ->whereIn('organization_id', Business::query()->whereKey($businessId)->select('organization_id'))
                ->exists());
    }
}
