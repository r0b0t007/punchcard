<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\OnboardingStep;
use App\Models\Business;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * The wizard's location step (CHW-31): the business's first site, its
 * address and timezone (stamps and rewards are shown in its local time).
 * Doing the step again updates that site rather than adding another; more
 * sites come with multi-location (CHW-53). Runs in the business's tenant.
 */
final readonly class SaveFirstLocation
{
    public function __construct(private CompleteStep $completeStep) {}

    public function handle(Business $business, string $name, string $address, string $timezone): Location
    {
        return DB::transaction(function () use ($business, $name, $address, $timezone): Location {
            $location = Location::query()->where('business_id', $business->id)->open()->oldest('id')->first()
                ?? new Location(['organization_id' => $business->organization_id, 'business_id' => $business->id]);

            $location->fill(['name' => $name, 'address' => $address, 'timezone' => $timezone])->save();
            $this->completeStep->handle($business, OnboardingStep::Location);

            return $location;
        });
    }
}
