<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\BusinessRole;
use App\Models\Location;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Closes a location (CHW-139): its stampers' assignments end, so their tags
 * can be assigned elsewhere, and nothing new happens there (ArchivedSites);
 * its history stays. The business's owner or an org admin of the
 * organization may do it, like deleting a location; admin actions in
 * bypass(). Only the platform admin restores it (RestoreArchived).
 *
 * archived_at is written before the stampers end, so an assignment racing
 * the archive either sees it (ArchivedSites locks the row) or is ended here.
 * Archiving an archived location changes nothing.
 */
final readonly class ArchiveLocation
{
    public function __construct(private TenantContext $context) {}

    public function handle(Location $location): void
    {
        if (! $this->context->isBypassed()) {
            $visible = Location::query()->whereKey($location->id)->exists();
            $mayArchive = $this->context->isOrgAdmin() || $this->context->businessRole() === BusinessRole::Owner;

            if (! $visible || ! $mayArchive) {
                throw new LogicException('Only the business\'s owner or an org admin archives a location.');
            }
        }

        DB::transaction(fn () => $this->context->bypass(function () use ($location): void {
            Location::query()->open()->whereKey($location->id)->update(['archived_at' => now()]);
            Stamper::query()->current()->where('location_id', $location->id)->update(['unassigned_at' => now()]);
            $location->refresh();
        }));
    }
}
