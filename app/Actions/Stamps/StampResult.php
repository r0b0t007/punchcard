<?php

declare(strict_types=1);

namespace App\Actions\Stamps;

use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\StampEvent;

/**
 * What AddStamps did: the ledger event, the enrollment after it and the
 * rewards it unlocked. replayed: the idempotency key matched an earlier
 * stamp, which is returned unchanged and nothing new was written.
 */
final readonly class StampResult
{
    /**
     * @param  list<Reward>  $rewards
     */
    public function __construct(
        public StampEvent $event,
        public CardEnrollment $enrollment,
        public array $rewards,
        public bool $replayed,
    ) {}
}
