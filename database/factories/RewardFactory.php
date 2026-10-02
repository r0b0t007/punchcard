<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CardMode;
use App\Enums\RewardStatus;
use App\Enums\RewardType;
use App\Models\CardEnrollment;
use App\Models\Reward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An available reward at milestone 1; organization_id is filled from the
 * enrollment. Create inside TenantContext::bypass() (Tests\Support\Tenants::reward())
 * or in the enrollment's organization.
 *
 * @extends Factory<Reward>
 */
class RewardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => CardEnrollment::factory(),
            'mode' => CardMode::Cyclic,
            'milestone' => 1,
            'reward_type' => RewardType::Item,
            'reward_text' => 'Free coffee',
            'status' => RewardStatus::Available,
            'unlocked_at' => now(),
        ];
    }
}
