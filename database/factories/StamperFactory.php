<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StamperStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\Stamper;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active stamper at a new location of its business; organization_id is
 * filled from the business. Create inside TenantContext::bypass()
 * (Tests\Support\Tenants::stamper()): stampers are registered by admins.
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
            'uid' => '04'.strtoupper(bin2hex(random_bytes(6))),
            'key_version' => 1,
            'last_counter' => 0,
            'status' => StamperStatus::Active,
        ];
    }
}
