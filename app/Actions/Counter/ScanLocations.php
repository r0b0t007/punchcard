<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use App\Models\Business;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;

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
        return array_values($this->query($business, $limitedTo)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Location $location): array => ['id' => $location->id, 'name' => $location->name])
            ->all());
    }

    /** One of those sites, by id, or null when it is none of them. */
    public function find(Business $business, ?int $limitedTo, int $id): ?Location
    {
        return $this->query($business, $limitedTo)->whereKey($id)->first();
    }

    /** @return Builder<Location> */
    private function query(Business $business, ?int $limitedTo): Builder
    {
        return Location::query()
            ->open()
            ->where('business_id', $business->id)
            ->when($limitedTo !== null, fn (Builder $query) => $query->whereKey($limitedTo));
    }
}
