<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\BusinessStatus;
use App\Enums\OrganizationType;
use App\Models\Business;
use App\Models\CardBusiness;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Closes a business (CHW-139): its stampers' assignments end, its locations
 * are archived, it stops honouring its cards and its status becomes
 * Archived, so its members get no tenant and nothing new happens there. Its
 * history stays: stamps, enrollments, rewards, memberships.
 *
 * Mirrors deleting a business: franchise HQ (an org admin, working across
 * the organization) closes one of its franchisees; closing an independent
 * café or a chain is a platform admin action, in bypass(). Only the platform
 * admin restores it (RestoreArchived).
 */
final readonly class ArchiveBusiness
{
    public function __construct(private TenantContext $context) {}

    public function handle(Business $business): void
    {
        if (! $this->context->isBypassed() && ! $this->isFranchiseHq($business)) {
            throw new LogicException('Franchise HQ, working across the organization, archives a franchisee; closing an independent café or a chain is a platform admin action.');
        }

        DB::transaction(fn () => $this->context->bypass(function () use ($business): void {
            Stamper::query()->current()->where('business_id', $business->id)->update(['unassigned_at' => now()]);
            Location::query()->open()->where('business_id', $business->id)->update(['archived_at' => now()]);
            CardBusiness::query()->where('business_id', $business->id)->delete();
            $business->forceFill(['status' => BusinessStatus::Archived])->save();
        }));
    }

    private function isFranchiseHq(Business $business): bool
    {
        return $this->context->isOrgAdmin()
            && $this->context->businessId() === null
            && $this->context->organizationId() === (int) $business->organization_id
            && $this->context->bypass(fn (): bool => Organization::query()
                ->whereKey($business->organization_id)
                ->where('type', OrganizationType::Franchise)
                ->exists());
    }
}
