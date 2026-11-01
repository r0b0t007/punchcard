<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Customers\SummariseCustomer;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\StampEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Summarises every business's customers from the ledger (CHW-44): once after
 * deploying the customers list, for who came in before it, or for one
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
                $customers = CardEnrollment::query()
                    ->whereIn('id', StampEvent::query()->select('enrollment_id')->where('business_id', $each->id)->whereIn('source', StampSource::presenceValues()))
                    ->distinct()->pluck('user_id');

                foreach ($customers as $userId) {
                    $summariseCustomer->handle($each->id, (int) $each->organization_id, (int) $userId);
                }

                $count += $customers->count();
            }

            return $count;
        });

        $this->info("Summarised {$count} customers.");

        return self::SUCCESS;
    }
}
