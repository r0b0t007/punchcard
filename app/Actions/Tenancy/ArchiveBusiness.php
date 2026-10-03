<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\OrganizationType;
use App\Models\Business;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Closes a business (CHW-139): it is archived, its locations too and its
 * stampers' assignments end, so its members get no tenant and nothing new
 * happens there (ArchivedSites). Everything else stays as it was, so a
 * restore loses nothing: stamps, enrollments, rewards, memberships, the cards
 * it honours, and its status (a suspension or a pending verification).
 *
 * Mirrors deleting a business: franchise HQ (an org admin, working across
 * the organization) closes one of its franchisees; closing an independent
 * café or a chain is a platform admin action, in bypass(). Only the platform
 * admin restores it (RestoreArchived).
 */
final readonly class ArchiveBusiness
{
    public function __construct(
        private TenantContext $context,
        private CloseSites $closeSites,
    ) {}

    public function handle(Business $business): void
    {
        if (! $this->context->isBypassed() && ! $this->isFranchiseHq($business)) {
            throw new LogicException('Franchise HQ, working across the organization, archives a franchisee; closing an independent café or a chain is a platform admin action.');
        }

        DB::transaction(fn () => $this->context->bypass(function () use ($business): void {
            $this->closeSites->handle('business_id', $business->id, Business::query()->whereKey($business->id));
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
