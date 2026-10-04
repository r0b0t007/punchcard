<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * For Actions whose work must commit on its own: the tap Actions spend an NFC
 * counter that a caller's rollback must never revive, and dispatch listeners
 * at their own commit, in the tenant they set. They refuse to run inside a
 * caller's transaction. Tests run inside RefreshDatabase's transaction, which
 * counts as none.
 */
final class Outermost
{
    public static function assert(string $action): void
    {
        $outside = app()->runningUnitTests() ? 1 : 0;

        if (DB::transactionLevel() > $outside) {
            throw new LogicException("{$action} runs outside any transaction: its work must commit on its own.");
        }
    }
}
