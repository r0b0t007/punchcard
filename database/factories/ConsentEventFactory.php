<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsentEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A consent change: news from punchcard ticked at sign-up, unless told
 * otherwise.
 *
 * @extends Factory<ConsentEvent>
 */
class ConsentEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => ConsentEvent::KIND_PUNCHCARD_NEWS,
            'granted' => true,
            'source' => ConsentEvent::SOURCE_SIGN_UP,
            'created_at' => now(),
        ];
    }
}
