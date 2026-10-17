<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CounterScan;
use App\Models\User;
use App\Support\MemberQr\MemberQrToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create scans in TenantContext::bypass(), with a business and one of its
 * locations: a scan is site data. The app makes them only from a checked
 * code (ScanMemberQr); this is for tests that need one as it stands.
 *
 * @extends Factory<CounterScan>
 */
class CounterScanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'staff_id' => User::factory(),
            'member_qr_window' => MemberQrToken::windowAt(now()),
            'scanned_at' => now(),
            'expires_at' => now()->addSeconds(CounterScan::SECONDS),
        ];
    }
}
