<?php

declare(strict_types=1);

namespace App\Policies;

use App\Actions\Stamps\WorksAt;
use App\Models\CounterScan;
use App\Models\User;

/**
 * A scan of a member QR (CHW-30) is the staff member's who made it, while
 * they still work at its site (removed, or limited to another site since,
 * they can't): only they see its result (S2), stamp or redeem with it. It resolves in the
 * tenant, so another business's is a 404 first; StampScan and RedeemScan
 * check again, with WorksAt.
 */
final class CounterScanPolicy
{
    public function use(User $user, CounterScan $scan): bool
    {
        return $scan->staff_id === $user->id
            && app(WorksAt::class)->handle($user->id, $scan->business_id, $scan->location_id);
    }
}
