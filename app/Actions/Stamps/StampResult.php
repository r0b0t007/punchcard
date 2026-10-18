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
 * dayFilled: the stamp's local day (Y-m-d) when it filled it to the card's
 * daily cap, which may tell the owners of a streak (CHW-36).
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
        public ?string $dayFilled = null,
    ) {}
}
