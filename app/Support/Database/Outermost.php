<?php

declare(strict_types=1);

namespace App\Support\Database;

use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * For Actions whose work must commit on its own: the tap Actions spend an NFC
 * counter that a caller's rollback must never revive, and dispatch listeners
 * at their own commit, in the tenant they set. They refuse to run inside a
 * caller's transaction. Tests run inside RefreshDatabase's transaction, which
 * counts as none.
 *
 * Their transaction runs again when the database aborts it to break a
 * deadlock (CHW-310: a tap holding a stamper takes the armer's or customer's
 * key, while deleting that account holds the user, then the stamper). Rolled
 * back whole each time, events queued for the commit included, so the work
 * is done once. Laravel retries only an outermost transaction: one more
 * reason these refuse a caller's.
 */
final class Outermost
{
    /** Runs of the work, when the database picks it to break a deadlock. */
    public const int ATTEMPTS = 3;

    public static function assert(string $action): void
    {
        $outside = app()->runningUnitTests() ? 1 : 0;

        if (DB::transactionLevel() > $outside) {
            throw new LogicException("{$action} runs outside any transaction: its work must commit on its own.");
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public static function transaction(string $action, Closure $work): mixed
    {
        self::assert($action);

        return DB::transaction($work, self::ATTEMPTS);
    }
}
