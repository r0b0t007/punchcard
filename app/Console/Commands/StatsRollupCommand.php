<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Stats\RollUpEndedDays;
use App\Console\Commands\Concerns\ReadsTextInput;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Rolls up businesses' ended days from the ledger (CHW-45): with --from,
 * every day from then (history, or a gap the hourly schedule left), again
 * for days already rolled up; without, what the schedule would. Safe to run
 * again: each day is rewritten whole.
 */
#[Signature('punchcard:stats:rollup {--business= : Only this business id} {--from= : The first local day, Y-m-d}')]
#[Description('Roll up businesses\' daily figures from their stamps')]
class StatsRollupCommand extends Command
{
    use ReadsTextInput;

    public function handle(RollUpEndedDays $rollUpEndedDays): int
    {
        $business = $this->textOption('business');
        $from = $this->textOption('from');

        // A real day, as written: 2026-02-31 would quietly become 3 March.
        if ($from !== null && (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $from, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))) {
            $this->error(__('The first day must be a date, as 2026-01-31.'));

            return self::INVALID;
        }

        if ($business !== null && ! ctype_digit($business)) {
            $this->error(__('The business must be its id, a number.'));

            return self::INVALID;
        }

        $count = $rollUpEndedDays->handle(CarbonImmutable::now(), $business === null ? null : (int) $business, $from);

        $this->info(trans_choice(':count day rolled up.|:count days rolled up.', $count));

        return self::SUCCESS;
    }
}
