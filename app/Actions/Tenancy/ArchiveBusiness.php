<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

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
 * Closes a business (CHW-139): it is archived, its locations too, its
 * stampers' assignments end and it stops honouring its cards, so its members
 * get no tenant and nothing new happens there. Its history stays: stamps,
 * enrollments, rewards, memberships, and its status (a suspension or a
 * pending verification survives a restore).
 *
 * Mirrors deleting a business: franchise HQ (an org admin, working across
 * the organization) closes one of its franchisees; closing an independent
 * café or a chain is a platform admin action, in bypass(). Only the platform
 * admin restores it (RestoreArchived). archived_at is written first, like in
 * ArchiveLocation, and archiving an archived business changes nothing.
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
            Business::query()->whereKey($business->id)->whereNull('archived_at')->update(['archived_at' => now()]);
            Location::query()->open()->where('business_id', $business->id)->update(['archived_at' => now()]);
            Stamper::query()->current()->where('business_id', $business->id)->update(['unassigned_at' => now()]);
            CardBusiness::query()->where('business_id', $business->id)->delete();
            $business->refresh();
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
