<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\Business;
use App\Models\Location;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;

/**
 * The business (id or slug) and optional location (id) a stamper command
 * names, read in bypass(): the platform admin works across tenants.
 */
trait FindsStamperSite
{
    private function namedBusiness(TenantContext $context): ?Business
    {
        $named = (string) $this->argument('business');

        return $context->bypass(fn (): ?Business => ctype_digit($named)
            ? Business::query()->whereKey((int) $named)->first()
            : Business::query()->where('slug', $named)->first());
    }

    /** @return Location|false|null null when none is named, false when the named one does not exist */
    private function namedLocation(TenantContext $context): Location|false|null
    {
        $named = $this->option('location');

        if ($named === null) {
            return null;
        }

        $location = ctype_digit((string) $named)
            ? $context->bypass(fn (): ?Location => Location::query()->whereKey((int) $named)->first())
            : null;

        return $location ?? false;
    }

    /** "A1, Main counter (#3)": where a stamper now is. */
    private function whereStamperIs(TenantContext $context, Business $business, Stamper $stamper): string
    {
        $location = $context->bypass(fn (): Location => Location::query()->findOrFail($stamper->location_id));

        return "{$business->name}, {$location->name} (#{$location->id})";
    }
}
