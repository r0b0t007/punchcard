<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\Business;
use App\Models\Location;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Reopens an archived organization, business or location (CHW-139): a
 * platform admin action only, in bypass(); tenants never un-archive. Only
 * archived_at is cleared: a business keeps the status it had (suspended,
 * pending, verified). Top down: a business stays closed while its
 * organization is archived, a location while its business is. Nothing else
 * is reattached: the admin assigns tags again and re-adds the business to its
 * cards explicitly, and restoring an organization leaves its businesses
 * archived until each is restored.
 */
final readonly class RestoreArchived
{
    public function __construct(private TenantContext $context) {}

    public function handle(Organization|Business|Location $archived): void
    {
        if (! $this->context->isBypassed()) {
            throw new LogicException('Restoring an archive is a platform admin action, in TenantContext::bypass().');
        }

        DB::transaction(function () use ($archived): void {
            $parentArchived = match (true) {
                $archived instanceof Business => Organization::query()->whereKey($archived->organization_id)->whereNotNull('archived_at')->exists(),
                $archived instanceof Location => Business::query()->whereKey($archived->business_id)->whereNotNull('archived_at')->exists(),
                default => false,
            };

            if ($parentArchived) {
                throw new LogicException('Restore the archived organization or business it belongs to first.');
            }

            $restored = $archived->newQuery()->whereKey($archived->getKey())->whereNotNull('archived_at')->update(['archived_at' => null]);

            if ($restored === 0) {
                throw new LogicException('Only an archived organization, business or location is restored.');
            }

            $archived->refresh();
        });
    }
}
