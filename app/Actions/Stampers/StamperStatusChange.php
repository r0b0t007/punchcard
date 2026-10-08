<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use App\Enums\StamperStatus;
use App\Models\Stamper;

/** What SetStamperStatus did: the stamper, and the status it had before, so the admin knows whether anything changed. */
final readonly class StamperStatusChange
{
    public function __construct(
        public Stamper $stamper,
        public StamperStatus $previous,
    ) {}

    public function changed(): bool
    {
        return $this->previous !== $this->stamper->status;
    }
}
