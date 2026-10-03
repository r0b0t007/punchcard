<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database: a test user and the demo (an
     * independent café and a franchise to click through). Everything here has
     * a known password, so it refuses every environment but local and testing,
     * before writing anything.
     */
    public function run(): void
    {
        DemoSeeder::assertDemoEnvironment();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(DemoSeeder::class);
    }
}
