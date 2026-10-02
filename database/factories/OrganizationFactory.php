<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingEntity;
use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'type' => OrganizationType::Independent,
            'billing_entity' => BillingEntity::Organization,
            'white_label' => false,
        ];
    }

    public function franchise(): static
    {
        return $this->state(['type' => OrganizationType::Franchise]);
    }
}
