<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Actions\Stampers\SiteName;
use App\Actions\Stampers\StamperRefused;
use App\Models\Business;
use App\Models\Location;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * What the stamper commands share: the business (slug, or id) and optional
 * location (id) they name, read in bypass() since the platform admin works
 * across tenants, the label, the refusal, and the line saying where the
 * stamper now is. Nothing printed carries key material.
 */
trait FindsStamperSite
{
    /**
     * @param  Closure(string, Business, ?Location, ?string): Stamper  $action  uid, business, location, label
     * @param  Closure(Stamper, string): string  $done  the stamper and where it is, to the line printed
     */
    private function onStamperSite(TenantContext $context, Closure $action, Closure $done): int
    {
        $business = $this->namedBusiness($context);

        if (! $business instanceof Business) {
            $this->error('No business with that slug or id.');

            return self::FAILURE;
        }

        $location = $this->namedLocation($context);

        if ($location === false) {
            $this->error('No location with that id.');

            return self::FAILURE;
        }

        $label = $this->option('label');

        try {
            $stamper = $action((string) $this->argument('uid'), $business, $location, is_string($label) ? $label : null);
        } catch (StamperRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info($done($stamper, "{$business->name}, ".SiteName::of($stamper->location)));

        return self::SUCCESS;
    }

    /** By slug first, so a business whose slug is all digits is still found by it; then by id. */
    private function namedBusiness(TenantContext $context): ?Business
    {
        $named = (string) $this->argument('business');

        return $context->bypass(fn (): ?Business => Business::query()->where('slug', $named)->first()
            ?? (ctype_digit($named) ? Business::query()->whereKey((int) $named)->first() : null));
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
}
