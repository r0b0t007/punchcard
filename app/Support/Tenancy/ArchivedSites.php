<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\Location;
use LogicException;

/**
 * Nothing new happens at an archived business or location (CHW-139): no
 * stamper assigned there, no stamp recorded there, no card honoured there.
 * Its history stays; ArchiveLocation, ArchiveBusiness and RestoreArchived
 * change the state. Checked on every insert, also in bypass().
 */
final class ArchivedSites
{
    /** Throws when the business or the location (either may be null) is archived. */
    public static function assertOpen(mixed $businessId, mixed $locationId, string $what): void
    {
        $archived = app(TenantContext::class)->bypass(
            fn (): bool => ($businessId !== null && Business::query()->whereKey($businessId)->where('status', BusinessStatus::Archived)->exists())
                || ($locationId !== null && Location::query()->whereKey($locationId)->whereNotNull('archived_at')->exists()),
        );

        if ($archived) {
            throw new LogicException("{$what} cannot be at an archived business or location.");
        }
    }
}
