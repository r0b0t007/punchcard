<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StamperStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\NfcTag;
use App\Models\Stamper;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A new tag assigned, active, at a new location of its business;
 * organization_id is filled from the business. Create inside
 * TenantContext::bypass() (Tests\Support\Tenants::stamper()): admins assign tags.
 *
 * @extends Factory<Stamper>
 */
class StamperFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'location_id' => fn (array $attributes): int => Location::factory()->create(['business_id' => $attributes['business_id']])->id,
            'nfc_tag_id' => NfcTag::factory(),
            'status' => StamperStatus::Active,
        ];
    }
}
