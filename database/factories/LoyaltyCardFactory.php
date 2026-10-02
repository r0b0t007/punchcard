<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CardMode;
use App\Enums\RewardType;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Makes its own organization unless given one: use ->for($organization),
 * inside TenantContext::bypass() or as that organization's admin. Attach the
 * businesses that honour it with ->businesses()->attach().
 *
 * @extends Factory<LoyaltyCard>
 */
class LoyaltyCardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => ucfirst(fake()->word()).' card',
            'stamps_required' => 10,
            'mode' => CardMode::Cyclic,
            'reward_type' => RewardType::Item,
            'reward_text' => 'Free coffee',
            'active' => true,
        ];
    }
}
