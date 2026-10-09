<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\Business;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Stamper;
use Illuminate\Database\Eloquent\Builder;

/**
 * The archive Actions' shared step (CHW-139), inside their transaction and
 * bypass(): archives an organization, business or location with everything
 * under it, and ends the current stamper assignments there, freeing their
 * tags. Archiving something already archived keeps its first date.
 *
 * The order is the lock order every guarded write follows (ArchivedSites):
 * 1. end the stampers: a tap holding its stamper's lock finishes first, and a
 *    tap that comes later finds no current stamper;
 * 2. archive the rows top down (organization, businesses, locations): a write
 *    that share-locked one of them first commits before, else sees the archive;
 * 3. end the stampers again: a tag assigned while step 2 waited is ended too.
 */
final readonly class CloseSites
{
    /**
     * @param  'location_id'|'business_id'|'organization_id'  $column
     * @param  Builder<Organization>|Builder<Business>  ...$above  the organization and business rows to archive, top down
     */
    public function handle(string $column, int $id, Builder ...$above): void
    {
        $this->endStampers($column, $id);

        foreach ($above as $rows) {
            $rows->whereNull('archived_at')->update(['archived_at' => now()]);
        }

        Location::query()->open()->where($column === 'location_id' ? 'id' : $column, $id)->update(['archived_at' => now()]);
        $this->endStampers($column, $id);
    }

    /**
     * Locks them first, in the stampers' lock order, as SuspendBusiness does, so the two never deadlock.
     *
     * @param  'location_id'|'business_id'|'organization_id'  $column
     */
    private function endStampers(string $column, int $id): void
    {
        $ids = Stamper::query()->current()->where($column, $id)->inLockOrder()->pluck('id');
        Stamper::query()->whereKey($ids)->update(['unassigned_at' => now()]);
    }
}
