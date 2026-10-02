<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * organization_id is filled from the card. Create inside TenantContext::bypass()
 * (Tests\Support\Tenants::enroll()) or in the card's organization.
 *
 * @extends Factory<CardEnrollment>
 */
class CardEnrollmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_id' => LoyaltyCard::factory(),
            'user_id' => User::factory(),
            'referral_code' => strtoupper(fake()->unique()->bothify('????####')),
        ];
    }
}
