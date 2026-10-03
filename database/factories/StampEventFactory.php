<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StampSource;
use App\Models\StampEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One QR stamp. Give it the enrollment, business and location (organization_id
 * is filled from the business), inside TenantContext::bypass() or in that
 * business: Tests\Support\Tenants::stamp() does.
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
        ];
    }
}
