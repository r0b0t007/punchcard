<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Customers\CustomersOfBusiness;
use App\Actions\Customers\SummariseCustomer;
use App\Models\Business;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Summarises every business's customers from the ledger (CHW-44), and the
 * days they came in (CHW-45): for who came in before them, again after
 * changing config('punchcard.customers'), or for one business. A business
 * at a time, so memory stays one business's customers; each customer in
 * their own short transaction. Safe to run again: each customer is
 * rewritten whole.
 *
 * --once is the deploy script's (docs/runbooks/deploy.md): it runs after
 * queue:restart, so every stamp the old release's worker took is in the
 * ledger it reads, and it does nothing once it has finished on a server.
 */
#[Signature('punchcard:customers:summarise {--business= : Only this business id} {--once : Only if it never finished here}')]
#[Description('Summarise businesses\' customers from their stamps')]
class CustomersSummariseCommand extends Command
{
    /** Set when a full run finished: the visit days (CHW-45) and the network rows (CHW-365) are filled from the ledger. */
    public const string DONE = 'punchcard:customers:summarised:network';

    public function handle(SummariseCustomer $summariseCustomer, CustomersOfBusiness $customersOfBusiness, TenantContext $context): int
    {
        $business = $this->option('business');

        if ($this->option('once') && Cache::has(self::DONE)) {
            $this->info(__('Customers already summarised.'));

            return self::SUCCESS;
        }

        $count = $context->bypass(function () use ($summariseCustomer, $customersOfBusiness, $business): int {
            $count = 0;
            $businesses = Business::query()->when($business !== null, fn ($query) => $query->whereKey((int) $business))->lazyById(100);

            foreach ($businesses as $each) {
                foreach ($customersOfBusiness->handle($each->id) as $userId) {
                    $summariseCustomer->handle($each->id, (int) $each->organization_id, $userId);
                    $count++;
                }
            }

            return $count;
        });

        if ($business === null) {
            Cache::forever(self::DONE, true);
        }

        $this->info(trans_choice(':count customer summarised.|:count customers summarised.', $count));

        return self::SUCCESS;
    }
}
