<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * organization_id is filled from the business by BelongsToBusiness.
 *
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->streetName(),
            'address' => fake()->address(),
            'lat' => fake()->latitude(35.5, 35.9),
            'lng' => fake()->longitude(-5.9, -5.3),
            'timezone' => 'Africa/Casablanca',
        ];
    }
}
