<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Customers\SummariseCustomer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

/**
 * A business's customers summarised again, on the queue (CHW-45): after one
 * of its sites changed timezone, their visits fall on other local days, for
 * the customers list (CHW-44) and the dashboard's visit days alike. Retried:
 * each customer is rewritten whole, so running it again is harmless.
 */
#[Backoff([60, 300])]
#[Tries(3)]
final class SummariseBusinessCustomers implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $businessId, public readonly int $organizationId) {}

    public function handle(SummariseCustomer $summariseCustomer): void
    {
        $summariseCustomer->everyoneAt($this->businessId, $this->organizationId);
    }
}
