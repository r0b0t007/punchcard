<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CampaignSegment;
use App\Models\Business;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A campaign to all of a business's followers, due now; organization_id is
 * filled from the business. Create inside TenantContext::bypass(), or in its
 * tenant.
 *
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'idempotency_key' => fake()->uuid(),
            'title' => 'Slow Tuesday?',
            'body' => 'Two stamps for every coffee until 4 pm today.',
            'segment' => CampaignSegment::All,
            'send_at' => now(),
        ];
    }
}
