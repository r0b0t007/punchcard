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
 * At most one change to the row a day (UTC): an account the request has
 * already loaded as active today costs no query, else one conditional
 * UPDATE, which only ever moves forward. Never an anonymised account, and
 * never updated_at, which stays the profile's last change. Bookkeeping: a
 * write that fails is reported, and never fails the stamp, redemption or page
 * it follows (once a stamp commits, its EnrollmentChanged and DailyCapReached
 * still go out).
 *
 * Scoped: during a web request RecordLastActive holds the writes of stamps
 * and redemptions until the response is sent, so a tap never waits on the
 * user row.
 */
final class RecordActivity
{
    private bool $holding = false;

    /** @var array<int, CarbonImmutable> user id => latest activity, waiting for the response */
    private array $waiting = [];

    public function handle(User $user, CarbonInterface $at): void
    {
        $at = CarbonImmutable::instance($at)->utc();

        if ($user->isAnonymised() || $user->last_active_at?->greaterThanOrEqualTo($at->startOfDay()) === true) {
            return;
        }

        if ($this->write($user->id, $at) > 0) {
            $this->noted($user, $at);
        }
    }

    /**
     * From a stamp or a redemption: once it commits, so one rolled back counts
     * for nothing, and the user row is never locked inside the ledger's lock
     * order (tag, stamper, enrollment), which DeleteAccount reverses. Held
     * until the response is sent during a web request, else written then.
     */
    public function later(int $userId, CarbonInterface $at): void
    {
        $at = CarbonImmutable::instance($at)->utc();

        DB::afterCommit(function () use ($userId, $at): void {
            if (! $this->holding) {
                $this->write($userId, $at);

                return;
            }

            $this->waiting[$userId] = isset($this->waiting[$userId]) ? $at->max($this->waiting[$userId]) : $at;
        });
    }

    /** Holds the writes of later() until release() (RecordLastActive, for the request). */
    public function hold(): void
    {
        $this->holding = true;
    }

    /**
     * Once the response is sent: the writes held for it, then the signed-in
     * user's own activity, which a held write for them already covers.
     */
    public function release(?User $signedIn, CarbonInterface $now): void
    {
        [$waiting, $this->waiting, $this->holding] = [$this->waiting, [], false];

        foreach ($waiting as $userId => $at) {
            if ($this->write($userId, $at) > 0 && $signedIn?->id === $userId) {
                $this->noted($signedIn, $at);
            }
        }

        if ($signedIn instanceof User) {
            $this->handle($signedIn, $now);
        }
    }

    /** The loaded user as the write left it, so the request asks no more today. */
    private function noted(User $user, CarbonImmutable $at): void
    {
        $user->forceFill(['last_active_at' => $at, 'inactivity_warned_at' => null])->syncOriginalAttributes(['last_active_at', 'inactivity_warned_at']);
    }

    private function write(int $userId, CarbonImmutable $at): int
    {
        return rescue(fn (): int => User::withoutTimestamps(fn (): int => User::query()->whereKey($userId)->notAnonymised()
            ->where(fn (Builder $query): Builder => $query->whereNull('last_active_at')->orWhere('last_active_at', '<', $at->startOfDay()))
            ->update(['last_active_at' => $at, 'inactivity_warned_at' => null])), 0);
    }
}
