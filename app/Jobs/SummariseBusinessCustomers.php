<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Customers\CustomersOfBusiness;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

/**
 * A business's customers summarised again, on the queue (CHW-45): after one
 * of its sites changed timezone, their visits fall on other local days, for
 * the customers list (CHW-44) and the dashboard's visit days alike. Hands
 * out one job per customer (SummariseBusinessCustomer), so a big business
 * never outlasts the worker's timeout and a failure retries one customer,
 * not all of them. Retried: handing them out again is harmless.
 */
#[Backoff([60, 300])]
#[Tries(3)]
final class SummariseBusinessCustomers implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $businessId, public readonly int $organizationId) {}

    public function handle(CustomersOfBusiness $customersOfBusiness): void
    {
        foreach ($customersOfBusiness->handle($this->businessId) as $userId) {
            SummariseBusinessCustomer::dispatch($this->businessId, $this->organizationId, $userId);
        }
    }
}
