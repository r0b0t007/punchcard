<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\Business;

/**
 * The owner opened the printable QR stand (CHW-31): "Print your QR stand"
 * is ticked on the setup checklist, the first time only. Runs in the
 * business's tenant, as its owner.
 */
final readonly class MarkQrStandOpened
{
    public function handle(Business $business): void
    {
        Business::query()->whereKey($business->id)->whereNull('qr_stand_opened_at')->update(['qr_stand_opened_at' => now()]);
    }
}
