<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Notes that an account is in use (CHW-58): a signed-in request
 * (RecordLastActive), a stamp that proves the customer was there (AddStamps)
 * or a redemption (RedeemReward). What the inactivity rule reads
 * (docs/privacy/retention.md), so it also withdraws a warning already sent.
 *
 * Once a day (UTC) at most: an account the request has already loaded as
 * active today costs no query, else one conditional UPDATE, which only ever
 * moves forward. Never an anonymised account, and never updated_at, which
 * stays the profile's last change. Bookkeeping: a write that fails is
 * reported, and never fails the stamp, redemption or page it follows (once a
 * stamp commits, its EnrollmentChanged and DailyCapReached still go out).
 */
final readonly class RecordActivity
{
    public function handle(User $user, CarbonInterface $at): void
    {
        $at = CarbonImmutable::instance($at)->utc();

        if ($user->isAnonymised() || $user->last_active_at?->greaterThanOrEqualTo($at->startOfDay()) === true) {
            return;
        }

        if ($this->write($user->id, $at) > 0) {
            $user->forceFill(['last_active_at' => $at, 'inactivity_warned_at' => null])->syncOriginalAttributes(['last_active_at', 'inactivity_warned_at']);
        }
    }

    /**
     * From a stamp or a redemption: once it commits, so one rolled back counts
     * for nothing, and the user row is never locked inside the ledger's lock
     * order (tag, stamper, enrollment), which DeleteAccount reverses.
     */
    public function later(int $userId, CarbonInterface $at): void
    {
        $at = CarbonImmutable::instance($at)->utc();

        DB::afterCommit(fn (): int => $this->write($userId, $at));
    }

    private function write(int $userId, CarbonImmutable $at): int
    {
        return rescue(fn (): int => User::withoutTimestamps(fn (): int => User::query()->whereKey($userId)->notAnonymised()
            ->where(fn (Builder $query): Builder => $query->whereNull('last_active_at')->orWhere('last_active_at', '<', $at->startOfDay()))
            ->update(['last_active_at' => $at, 'inactivity_warned_at' => null])), 0);
    }
}
