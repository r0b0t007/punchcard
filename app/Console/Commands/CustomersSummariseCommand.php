<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Customers\SummariseCustomer;
use App\Models\Business;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Summarises every business's customers from the ledger (CHW-44), and the
 * days they came in (CHW-45): once after deploying the customers list and
 * again after the visit days, for who came in before them, again after
 * changing config('punchcard.customers') or a site's timezone, or for one
 * business. A business at a time, so memory stays one business's customers.
 * Safe to run again: each customer is rewritten whole.
 */
#[Signature('punchcard:customers:summarise {--business= : Only this business id}')]
#[Description('Summarise businesses\' customers from their stamps')]
class CustomersSummariseCommand extends Command
{
    public function handle(SummariseCustomer $summariseCustomer, TenantContext $context): int
    {
        $business = $this->option('business');

        $count = $context->bypass(function () use ($summariseCustomer, $business): int {
            $count = 0;
            $businesses = Business::query()->when($business !== null, fn ($query) => $query->whereKey((int) $business))->lazyById(100);

            foreach ($businesses as $each) {
                $count += $summariseCustomer->everyoneAt($each->id, (int) $each->organization_id);
            }

            return $count;
        });

        $this->info(trans_choice(':count customer summarised.|:count customers summarised.', $count));

        return self::SUCCESS;
    }
}
