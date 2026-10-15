<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StaffInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create invitations in TenantContext::bypass() or as an owner, with a business
 * (->for($business)): an invitation is site data.
 *
 * @extends Factory<StaffInvitation>
 */
class StaffInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'expires_at' => now()->addDays(StaffInvitation::DAYS),
        ];
    }
}
