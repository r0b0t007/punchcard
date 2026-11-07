<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Models\Business;

/**
 * A business's average ticket (CHW-48, B10), from a campaign's results:
 * what a visit is worth, in centimes, to estimate the revenue a campaign's
 * extra visits brought. Saved by whoever runs the business, in its tenant
 * (Business::assertTenantWrite).
 */
final readonly class SetAverageTicket
{
    public function handle(Business $business, int $centimes): void
    {
        $business->forceFill(['average_ticket' => $centimes])->save();
    }
}
