<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Tenancy\PlatformBuilder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform data: no tenant's rows (NFC tags, the tap log). It reads as empty
 * outside TenantContext::bypass(), and its PlatformBuilder refuses every write
 * there (tests/Unit/TenancyBoundaryTest checks every platform model declares
 * #[UseEloquentBuilder(PlatformBuilder::class)], so the read guard never
 * comes without the write guard); admin actions and the tap endpoint work in bypass().
 *
 * @phpstan-require-extends Model
 */
trait IsPlatformData
{
    protected static function bootIsPlatformData(): void
    {
        static::addGlobalScope(PlatformBuilder::PLATFORM_SCOPE, static function (Builder $query): void {
            if (! app(TenantContext::class)->isBypassed()) {
                $query->whereRaw('1 = 0');
            }
        });
    }
}
