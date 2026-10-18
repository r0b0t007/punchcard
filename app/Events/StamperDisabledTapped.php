<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone tapped a switched-off stamper (CHW-36): its refusal is committed,
 * and its business's owners are told (SendFraudAlerts, queued, so the tap
 * stays fast). Dispatched by ReceiveTap only: a stamper switched off after a
 * tap (refused when it is applied) is its owner's own doing. Ids only.
 */
final readonly class StamperDisabledTapped implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $stamperId,
        public int $tapId,
    ) {}
}
