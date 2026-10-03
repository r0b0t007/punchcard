<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\BusinessRole;
use App\Models\Location;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Closes a location (CHW-139): its stampers' assignments end, so their tags
 * can be assigned elsewhere, and nothing new happens there (ArchivedSites);
 * its history stays. The business's owner or an org admin of the
 * organization may do it, like deleting a location; admin actions in
 * bypass(). Only the platform admin restores it (RestoreArchived).
 */
final readonly class ArchiveLocation
{
    public function __construct(
        private TenantContext $context,
        private CloseSites $closeSites,
    ) {}

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
            $this->closeSites->handle('location_id', $location->id);
            $location->refresh();
        }));
    }
}
