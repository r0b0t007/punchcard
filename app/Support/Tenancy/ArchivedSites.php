<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Nothing new happens at an archived business or location, or in an archived
 * organization (CHW-139): no business, location, card or membership created,
 * no stamper assigned or moved there, no stamp or redemption recorded there
 * (a correction still is: the ledger must stay fixable), no card honoured
 * there, no customer enrolled. Its history stays; the archive Actions and
 * RestoreArchived change the state. One rule for every caller: a location is
 * closed when it, its business or its organization is archived.
 *
 * Model inserts and the updates that move a stamper or record a redemption
 * check it, also in bypass(); raw inserts only pass
 * TenantBuilder::guardRawWrite(). Writes that must not race an archive pass
 * $lock: inside a transaction (TenantBuilder opens one around every guarded
 * write), they share-lock the organization, the business, then the location,
 * the order CloseSites archives them after ending the stampers, so such a
 * write either commits first or sees the archive, and the two cannot
 * deadlock. A tap's stamp reads committed state in one query without
 * locking: the tap holds its stamper's lock, which the archive waits for
 * before it writes anything else (CHW-25).
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

    /**
     * @param  bool  $counter  also closed while the business is suspended (CHW-22): the counter (taps, scans,
     *                         manual stamps, redemptions) stops; an admin's own writes, and corrections, do not
     */
    public static function isOpen(mixed $businessId, mixed $locationId, bool $lock = false, bool $counter = false): bool
    {
        self::assertLockHolds($lock);

        return app(TenantContext::class)->bypass(function () use ($businessId, $locationId, $lock, $counter): bool {
            if ($lock) {
                $businessIds = array_unique(array_filter(
                    [$businessId, $locationId === null ? null : Location::query()->whereKey($locationId)->value('business_id')],
                    fn (mixed $id): bool => $id !== null,
                ));
                sort($businessIds);

                foreach ($businessIds as $id) {
                    if (! self::businessOpen($id, $lock, $counter)) {
                        return false;
                    }
                }

                return $locationId === null || Location::query()->whereKey($locationId)->sharedLock()->value('archived_at') === null;
            }

            if ($locationId === null) {
                return self::businessOpen($businessId, $lock, $counter);
            }

            $site = Location::query()
                ->whereKey($locationId)
                ->join('businesses', 'businesses.id', '=', 'locations.business_id')
                ->join('organizations', 'organizations.id', '=', 'businesses.organization_id')
                ->toBase()
                ->first(['locations.business_id', 'locations.archived_at as location_archived', 'businesses.archived_at as business_archived', 'businesses.status as business_status', 'organizations.archived_at as organization_archived']);

            if ($site === null) {
                return true;
            }

            $open = $site->location_archived === null && $site->business_archived === null && $site->organization_archived === null
                && (! $counter || $site->business_status !== BusinessStatus::Suspended->value);

            return $open && ($businessId === null || (int) $businessId === (int) $site->business_id || self::businessOpen($businessId, $lock, $counter));
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

    /**
     * The business and its organization, the business row locked when asked: an organization's archive writes its
     * businesses too. For the counter, a suspended business is closed as well: one read, under the same lock.
     */
    private static function businessOpen(mixed $businessId, bool $lock, bool $counter = false): bool
    {
        if ($businessId === null) {
            return true;
        }

        $business = Business::query()
            ->whereKey($businessId)
            ->join('organizations', 'organizations.id', '=', 'businesses.organization_id')
            ->when($lock, fn ($query) => $query->lock('for share of businesses'))
            ->toBase()
            ->first(['businesses.archived_at as business_archived', 'businesses.status as business_status', 'organizations.archived_at as organization_archived']);

        return $business === null || ($business->business_archived === null && $business->organization_archived === null
            && (! $counter || $business->business_status !== BusinessStatus::Suspended->value));
    }

    /** A shared lock outside a transaction ends with its SELECT and protects nothing. */
    private static function assertLockHolds(bool $lock): void
    {
        if ($lock && DB::transactionLevel() === 0) {
            throw new LogicException('Checking an archive with a lock needs a transaction, so the lock holds until the write commits.');
        }
    }
}
