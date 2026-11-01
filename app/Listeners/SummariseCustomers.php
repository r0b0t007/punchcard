<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Customers\SummariseCustomer;
use App\Events\EnrollmentChanged;
use App\Models\CardEnrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

/**
 * Each stamp and redemption updates the customer at the business where it
 * happened (CHW-44, SummariseCustomer), queued so the tap never waits on it.
 * Retried: the summary is rewritten whole, so running it again is harmless.
 */
#[Backoff([60, 300])]
#[Tries(3)]
final readonly class SummariseCustomers implements ShouldQueue
{
    public function __construct(private SummariseCustomer $summariseCustomer, private TenantContext $context) {}

    public function handle(EnrollmentChanged $event): void
    {
        $userId = $this->context->bypass(fn (): mixed => CardEnrollment::query()->whereKey($event->enrollmentId)
            ->where('organization_id', $event->organizationId)->value('user_id'));

        if ($userId !== null) {
            $this->summariseCustomer->handle($event->businessId, $event->organizationId, (int) $userId);
        }
    }
}
