<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\Location;
use App\Models\Stamper;

/**
 * The archive Actions' shared step (CHW-139), inside their transaction and
 * bypass(), after each wrote its own archived_at: archives the open
 * locations of a location, business or organization and ends the current
 * stamper assignments there, freeing their tags. Archiving something already
 * archived keeps its first date.
 */
final readonly class CloseSites
{
    /** @param  'location_id'|'business_id'|'organization_id'  $column */
    public function handle(string $column, int $id): void
    {
        Location::query()->open()->where($column === 'location_id' ? 'id' : $column, $id)->update(['archived_at' => now()]);
        Stamper::query()->current()->where($column, $id)->update(['unassigned_at' => now()]);
    }
}
