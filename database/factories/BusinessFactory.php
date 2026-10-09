<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'category' => 'cafe',
            'status' => BusinessStatus::Verified,
            'verified_at' => fn (array $attributes): ?CarbonInterface => BusinessStatus::tryFrom(($attributes['status'] ?? null) instanceof BusinessStatus ? $attributes['status']->value : (string) ($attributes['status'] ?? '')) === BusinessStatus::Verified ? now() : null,
        ];
    }
}
