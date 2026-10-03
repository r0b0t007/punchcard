<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\Business;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Closes an organization (CHW-139): it is archived, every business with it
 * (ArchiveBusiness) and its cards are deactivated, so nobody resolves into
 * it. Its history stays. A platform admin action only, in bypass();
 * RestoreArchived undoes it, one level at a time.
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
            Organization::query()->whereKey($organization->id)->whereNull('archived_at')->update(['archived_at' => now()]);

            Business::query()
                ->where('organization_id', $organization->id)
                ->whereNull('archived_at')
                ->get()
                ->each(fn (Business $business) => $this->archiveBusiness->handle($business));

            LoyaltyCard::query()->where('organization_id', $organization->id)->update(['active' => false]);
            $organization->refresh();
        });
    }
}
