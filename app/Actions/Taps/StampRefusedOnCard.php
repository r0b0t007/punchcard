<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use App\Actions\Stamps\StampRejected;
use RuntimeException;

/**
 * Inside ApplyTap: AddStamps refused the stamp on this card. Thrown out of
 * the savepoint (so a new enrollment rolls back) with what the tap keeps for
 * its result page: the card and its stamps at that moment.
 */
final class StampRefusedOnCard extends RuntimeException
{
    public function __construct(
        public readonly StampRejected $rejected,
        public readonly int $cardId,
        public readonly int $cardStamps,
    ) {
        parent::__construct($rejected->getMessage(), previous: $rejected);
    }
}
