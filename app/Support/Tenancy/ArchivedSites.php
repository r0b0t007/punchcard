<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Business;
use App\Models\Location;
use App\Models\Organization;
use LogicException;

/**
 * Nothing new happens at an archived business or location, or in an archived
 * organization (CHW-139): no location opened, no stamper assigned or moved
 * there, no stamp or redemption recorded there, no card honoured there. Its
 * history stays; ArchiveLocation, ArchiveBusiness, ArchiveOrganization and
 * RestoreArchived change the state.
 *
 * Model inserts and the updates that move a stamper or record a redemption
 * check it, also in bypass(); raw inserts only pass
 * TenantBuilder::guardRawWrite(). Assignments lock the business and location
 * rows (shared) so they serialize with an archive: the archive Actions write
 * archived_at first, then end stampers, so an assignment either sees the
 * archive or is ended by it. The tap path does not lock: it reads committed
 * state and the archive's stamper update serializes with its stamper lock.
 */
final class ArchivedSites
{
    /** Throws when the business (or its organization) or the location is archived; either id may be null. */
    public static function assertOpen(mixed $businessId, mixed $locationId, string $what, bool $lock = false): void
    {
        $archived = app(TenantContext::class)->bypass(function () use ($businessId, $locationId, $lock): bool {
            $business = $businessId === null ? null : Business::query()
                ->whereKey($businessId)
                ->when($lock, fn ($query) => $query->sharedLock())
                ->first(['id', 'organization_id', 'archived_at']);

            if ($business instanceof Business && ($business->archived_at !== null
                || Organization::query()->whereKey($business->organization_id)->whereNotNull('archived_at')->exists())) {
                return true;
            }

            return $locationId !== null && Location::query()
                ->whereKey($locationId)
                ->when($lock, fn ($query) => $query->sharedLock())
                ->value('archived_at') !== null;
        });

        if ($archived) {
            throw new LogicException("{$what} cannot be at an archived business or location.");
        }
    }
}
