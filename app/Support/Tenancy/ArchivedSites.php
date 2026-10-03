<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Business;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Nothing new happens at an archived business or location, or in an archived
 * organization (CHW-139): no business or location opened, no stamper assigned
 * or moved there, no stamp or redemption recorded there (a correction still
 * is: the ledger must stay fixable), no card honoured there, no customer
 * enrolled. Its history stays; the archive Actions and RestoreArchived change
 * the state. One rule for every caller: a location is closed when it, its
 * business or its organization is archived.
 *
 * Model inserts and the updates that move a stamper or record a redemption
 * check it, also in bypass(); raw inserts only pass
 * TenantBuilder::guardRawWrite(). Writes that must not race an archive pass
 * $lock and run inside a transaction: they read the business, then the
 * location, with a shared lock, the order the archive Actions write them
 * (archived_at first, then stampers), so such a write either sees the archive
 * or is ended by it, and the two cannot deadlock. The tap path reads committed
 * state in one query; the archive's stamper update serializes with its
 * stamper lock (CHW-25).
 */
final class ArchivedSites
{
    /** Throws when the location, its business or their organization is archived; either id may be null. */
    public static function assertOpen(mixed $businessId, mixed $locationId, string $what, bool $lock = false): void
    {
        if (! self::isOpen($businessId, $locationId, $lock)) {
            throw new LogicException("{$what} cannot be at an archived business or location.");
        }
    }

    /** Throws when the organization is archived. */
    public static function assertOrganizationOpen(mixed $organizationId, string $what, bool $lock = false): void
    {
        if (! self::isOrganizationOpen($organizationId, $lock)) {
            throw new LogicException("{$what} cannot be in an archived organization.");
        }
    }

    public static function isOpen(mixed $businessId, mixed $locationId, bool $lock = false): bool
    {
        self::assertLockHolds($lock);

        return app(TenantContext::class)->bypass(function () use ($businessId, $locationId, $lock): bool {
            if ($lock) {
                $businessIds = array_unique(array_filter(
                    [$businessId, $locationId === null ? null : Location::query()->whereKey($locationId)->value('business_id')],
                    fn (mixed $id): bool => $id !== null,
                ));
                sort($businessIds);

                foreach ($businessIds as $id) {
                    if (! self::businessOpen($id, $lock)) {
                        return false;
                    }
                }

                return $locationId === null || Location::query()->whereKey($locationId)->sharedLock()->value('archived_at') === null;
            }

            if ($locationId === null) {
                return self::businessOpen($businessId, $lock);
            }

            $site = Location::query()
                ->whereKey($locationId)
                ->join('businesses', 'businesses.id', '=', 'locations.business_id')
                ->join('organizations', 'organizations.id', '=', 'businesses.organization_id')
                ->toBase()
                ->first(['locations.business_id', 'locations.archived_at as location_archived', 'businesses.archived_at as business_archived', 'organizations.archived_at as organization_archived']);

            if ($site === null) {
                return true;
            }

            $open = $site->location_archived === null && $site->business_archived === null && $site->organization_archived === null;

            return $open && ($businessId === null || (int) $businessId === (int) $site->business_id || self::businessOpen($businessId, $lock));
        });
    }

    public static function isOrganizationOpen(mixed $organizationId, bool $lock = false): bool
    {
        self::assertLockHolds($lock);

        return $organizationId === null || app(TenantContext::class)->bypass(fn (): bool => Organization::query()
            ->whereKey($organizationId)
            ->when($lock, fn ($query) => $query->sharedLock())
            ->value('archived_at') === null);
    }

    /** The business and its organization, the business row locked when asked: an organization's archive writes its businesses too. */
    private static function businessOpen(mixed $businessId, bool $lock): bool
    {
        if ($businessId === null) {
            return true;
        }

        $business = Business::query()
            ->whereKey($businessId)
            ->join('organizations', 'organizations.id', '=', 'businesses.organization_id')
            ->when($lock, fn ($query) => $query->lock('for share of businesses'))
            ->toBase()
            ->first(['businesses.archived_at as business_archived', 'organizations.archived_at as organization_archived']);

        return $business === null || ($business->business_archived === null && $business->organization_archived === null);
    }

    /** A shared lock outside a transaction ends with its SELECT and protects nothing. */
    private static function assertLockHolds(bool $lock): void
    {
        if ($lock && DB::transactionLevel() === 0) {
            throw new LogicException('Checking an archive with a lock needs a transaction, so the lock holds until the write commits.');
        }
    }
}
