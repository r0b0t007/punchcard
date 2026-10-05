<?php

declare(strict_types=1);

namespace App\Actions\Stamps;

use App\Enums\StampRejection;
use App\Models\CardEnrollment;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * AddStamps refused the stamp; nothing was written. A cooldown says when the
 * next stamp is possible. A refusal made once the enrollment was locked also
 * carries its card and stamps at that moment (onCard), for a tap's result.
 */
final class StampRejected extends RuntimeException
{
    public function __construct(
        public readonly StampRejection $rejection,
        public readonly ?CarbonInterface $availableAt = null,
        public readonly ?int $cardId = null,
        public readonly ?int $cardStamps = null,
    ) {
        parent::__construct("Stamp refused: {$rejection->value}.");
    }

    /** The same refusal, with the card and stamps of the enrollment it was judged on. */
    public function onCard(CardEnrollment $enrollment): self
    {
        return new self($this->rejection, $this->availableAt, $enrollment->card_id, $enrollment->current_stamps);
    }
}
