<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\OrganizationRole;
use App\Models\Business;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Reopens an archived organization, business or location (CHW-139): a
 * platform admin action only, in bypass(); tenants never un-archive. Only
 * archived_at is cleared: a business keeps the status it had (suspended,
 * pending, verified). Top down: a business stays closed while its
 * organization is archived, a location while its business is. Archiving
 * kept the cards and the businesses that honour them, so those come back as
 * they were; stamper assignments ended for good, so the admin assigns the
 * tags again. Restoring an organization leaves its businesses and locations
 * archived until each is restored.
 *
 * An organization comes back only with an org admin whose account is still
 * there (CHW-308): one whose admins all deleted their accounts while it was
 * archived has nobody to run it, so the platform admin assigns one first.
 * Read after the row is written (locked), so an admin's deletion under way
 * (DeleteAccount share-locks it) has finished.
 */
final readonly class RestoreArchived
{
    public function __construct(private TenantContext $context) {}

    public function handle(Organization|Business|Location $archived): void
    {
        if (! $this->context->isBypassed()) {
            throw new LogicException('Restoring an archive is a platform admin action, in TenantContext::bypass().');
        }

        DB::transaction(function () use ($archived): void {
            // Locked, so an archive of the parent running now either comes first
            // (and this refuses) or archives the restored child again after it.
            $parentOpen = match (true) {
                $archived instanceof Business => ArchivedSites::isOrganizationOpen($archived->organization_id, lock: true),
                $archived instanceof Location => ArchivedSites::isOpen($archived->business_id, null, lock: true),
                default => true,
            };

            if (! $parentOpen) {
                throw new LogicException('Restore the archived organization or business it belongs to first.');
            }

            $restored = $archived->newQuery()->whereKey($archived->getKey())->whereNotNull('archived_at')->update(['archived_at' => null]);

            if ($restored === 0) {
                throw new LogicException('Only an archived organization, business or location is restored.');
            }

            if ($archived instanceof Organization && ! $this->hasAdmin($archived)) {
                throw new LogicException('Nobody is left to run this organization: assign an org admin before restoring it.');
            }

            $archived->refresh();
        });
    }

    private function hasAdmin(Organization $organization): bool
    {
        return OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('role', OrganizationRole::OrgAdmin)
            ->whereIn('user_id', User::query()->notAnonymised()->select('id'))
            ->exists();
    }
}
