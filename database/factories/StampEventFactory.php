<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StampSource;
use App\Models\StampEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * One QR stamp by a staff member, with its idempotency key. Give it the
 * enrollment, business and location (organization_id is filled from the
 * business), inside TenantContext::bypass(): only the stamp Actions record
 * stamps. Tests\Support\Tenants::stamp() does.
 *
 * @extends Factory<StampEvent>
 */
class StampEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => StampSource::Qr,
            'qty' => 1,
            'staff_id' => User::factory(),
            'idempotency_key' => fn (): string => (string) Str::uuid(),
        ];
    }
}
