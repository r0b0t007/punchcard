<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Customers\SummariseCustomer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

/**
 * One customer of a business summarised again, on the queue (CHW-45), handed
 * out by SummariseBusinessCustomers: short, and retried on its own. Retried:
 * the summary is rewritten whole, so running it again is harmless.
 */
#[Backoff([60, 300])]
#[Tries(3)]
final class SummariseBusinessCustomer implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $businessId, public readonly int $organizationId, public readonly int $userId) {}

    public function handle(SummariseCustomer $summariseCustomer): void
    {
        $summariseCustomer->handle($this->businessId, $this->organizationId, $this->userId);
    }
}
