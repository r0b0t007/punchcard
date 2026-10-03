<?php

declare(strict_types=1);

namespace App\Actions\Stamps;

use App\Enums\StampRejection;
use Carbon\CarbonInterface;
use RuntimeException;

/** AddStamps refused the stamp; nothing was written. A cooldown says when the next stamp is possible. */
final class StampRejected extends RuntimeException
{
    public function __construct(
        public readonly StampRejection $rejection,
        public readonly ?CarbonInterface $availableAt = null,
    ) {
        parent::__construct("Stamp refused: {$rejection->value}.");
    }
}
