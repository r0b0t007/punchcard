<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A claimed, not yet sent, campaign to a follower. Its business is its
 * campaign's (the composite key holds it to it); organization_id is filled
 * from the business. Create inside TenantContext::bypass(), or in its tenant.
 *
 * @extends Factory<CampaignSend>
 */
class CampaignSendFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'business_id' => fn (array $attributes): int => Campaign::query()->whereKey($attributes['campaign_id'])->firstOrFail()->business_id,
            'user_id' => User::factory(),
        ];
    }
}
