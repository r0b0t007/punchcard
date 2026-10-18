<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A stamp that proves presence filled a customer's day at a business: the
 * card's daily cap is reached (AddStamps, once the stamp is committed).
 * Three such days in a row tell the business's owners (CHW-36,
 * SendFraudAlerts). $date is the site's local day (Y-m-d). Ids only.
 */
final readonly class DailyCapReached implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $enrollmentId,
        public int $businessId,
        public int $locationId,
        public string $date,
    ) {}
}
