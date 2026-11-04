<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AutomationTrigger;
use App\Models\Automation;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A business's win-back automation, on, with our message; organization_id
 * is filled from the business. Create inside TenantContext::bypass(), or in
 * its tenant.
 *
 * @extends Factory<Automation>
 */
class AutomationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'trigger' => AutomationTrigger::WinBack,
            'active' => true,
        ];
    }
}
