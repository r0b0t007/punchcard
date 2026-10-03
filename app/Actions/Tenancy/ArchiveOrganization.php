<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Closes an organization (CHW-139): every business is archived
 * (ArchiveBusiness), its cards are deactivated and the organization is
 * archived, so nobody resolves into it. Its history stays. A platform admin
 * action only, in bypass(); RestoreArchived undoes it.
 */
final readonly class ArchiveOrganization
{
    public function __construct(
        private TenantContext $context,
        private ArchiveBusiness $archiveBusiness,
    ) {}

    public function handle(Organization $organization): void
    {
        if (! $this->context->isBypassed()) {
            throw new LogicException('Archiving an organization is a platform admin action, in TenantContext::bypass().');
        }

        DB::transaction(function () use ($organization): void {
            Business::query()
                ->where('organization_id', $organization->id)
                ->where('status', '!=', BusinessStatus::Archived)
                ->get()
                ->each(fn (Business $business) => $this->archiveBusiness->handle($business));

            LoyaltyCard::query()->where('organization_id', $organization->id)->update(['active' => false]);
            $organization->forceFill(['archived_at' => now()])->save();
        });
    }
}
