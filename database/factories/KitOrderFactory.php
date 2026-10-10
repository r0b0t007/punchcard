<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\KitOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A requested stamper kit for a business; organization_id is filled from
 * the business. Create inside TenantContext::bypass(), or in its tenant.
 *
 * @extends Factory<KitOrder>
 */
class KitOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'recipient_name' => fake()->name(),
            'phone' => '+212 6 12 34 56 78',
            'address' => fake()->streetAddress(),
            'city' => 'Tanger',
            'postal_code' => '90000',
        ];
    }
}
