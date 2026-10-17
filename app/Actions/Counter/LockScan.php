<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use App\Actions\Stamps\WorksAt;
use App\Models\CounterScan;
use App\Models\User;

/**
 * A scan locked for the staff member using it (StampScan, RedeemScan,
 * CHW-30): theirs, and only while they still work at its location (removed,
 * or limited to another site since, they can't), never on themselves. Call inside the
 * transaction that uses it. Tenant-scoped: another business's scan is not
 * found.
 */
final readonly class LockScan
{
    public function __construct(private WorksAt $worksAt) {}

    /** @throws ScanRefused */
    public function handle(CounterScan $scan, User $staff): CounterScan
    {
        $locked = CounterScan::query()->whereKey($scan->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->staff_id !== $staff->id || ! $this->worksAt->handle($staff->id, $locked->business_id, $locked->location_id)) {
            throw new ScanRefused(ScanRefusal::NotYours);
        }

        // ScanMemberQr never makes one of the staff member's own code; refused here too, whatever made the row.
        if ($locked->user_id === $staff->id) {
            throw new ScanRefused(ScanRefusal::OwnCode);
        }

        return $locked;
    }
}
