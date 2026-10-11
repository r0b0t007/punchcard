<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The demo with about 10,000 stamps at the café (CHW-33), to measure the
 * owner dashboard against: 800 café customers instead of 20, under the
 * demo's rules (DemoSeeder). Local and testing only; seed it into a
 * database of its own (`php artisan db:seed --class=DemoVolumeSeeder`).
 */
final class DemoVolumeSeeder extends Seeder
{
    public function run(): void
    {
        DemoSeeder::assertDemoEnvironment();

        $this->callWith(DemoSeeder::class, ['cafeCustomers' => 800]);
    }
}
