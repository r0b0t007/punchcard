<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use App\Models\Business;
use App\Models\Location;

/**
 * Where a staff member can scan member QRs (CHW-30): the open sites of the
 * business they work in, or only theirs when they are limited to one (as
 * TenantContext reads the limit). The scanner offers a choice when there are
 * several; ScanMemberQr checks WorksAt again on the one sent.
 */
final readonly class ScanLocations
{
    /**
     * @return list<array{id: int, name: string}>
     */
    public function handle(Business $business, ?int $limitedTo): array
    {
        return array_values(Location::query()
            ->open()
            ->where('business_id', $business->id)
            ->when($limitedTo !== null, fn ($query) => $query->whereKey($limitedTo))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Location $location): array => ['id' => $location->id, 'name' => $location->name])
            ->all());
    }
}
