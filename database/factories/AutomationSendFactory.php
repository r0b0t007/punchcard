<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AutomationTrigger;
use App\Models\Automation;
use App\Models\AutomationSend;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A claimed, not yet sent, automation message to a customer. Its business
 * and trigger are its automation's (the composite key holds them to it);
 * organization_id is filled from the business. Create inside
 * TenantContext::bypass(), or in its tenant.
 *
 * @extends Factory<AutomationSend>
 */
class AutomationSendFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'automation_id' => Automation::factory(),
            'business_id' => fn (array $attributes): int => $this->automation($attributes)->business_id,
            'trigger' => fn (array $attributes): AutomationTrigger => $this->automation($attributes)->trigger,
            'user_id' => User::factory(),
            'occasion' => 'quiet:2026-11-04',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function automation(array $attributes): Automation
    {
        return Automation::query()->whereKey($attributes['automation_id'])->firstOrFail();
    }
}
