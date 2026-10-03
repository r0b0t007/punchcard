<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NfcTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A fresh tag (key version 1, counter 0). Create inside TenantContext::bypass():
 * tags are platform state.
 *
 * @extends Factory<NfcTag>
 */
class NfcTagFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uid' => '04'.strtoupper(bin2hex(random_bytes(6))),
            'key_version' => 1,
            'last_counter' => 0,
        ];
    }
}
