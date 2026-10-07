<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Enums\BusinessStatus;
use App\Models\Business;

/**
 * A suspended business takes no taps, stamps or redemptions (CHW-22), on top
 * of its people getting no tenant (ResolveTenant). A pending one keeps
 * working, so a pilot café starts at once. Unlike an archive (ArchivedSites)
 * this blocks only the counter: an admin can still fix the business.
 */
final class SuspendedBusinesses
{
    public static function isSuspended(mixed $businessId): bool
    {
        return app(TenantContext::class)->bypass(
            fn (): bool => Business::query()->whereKey($businessId)->where('status', BusinessStatus::Suspended)->exists(),
        );
    }
}
