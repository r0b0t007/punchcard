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

    /**
     * What to tell the admin, saying when the stamper already had that status:
     * the business may have paused it (runbook step 1 asks to note it).
     */
    public function summary(): string
    {
        $where = "Stamper #{$this->stamper->id} at {$this->stamper->business->name}, ".SiteName::of($this->stamper->location);
        $disabled = $this->stamper->status === StamperStatus::Disabled;

        return match (true) {
            ! $this->changed() && $disabled => "{$where} was already disabled, perhaps by the business: leave it disabled after the re-key.",
            ! $this->changed() => "{$where} was already enabled.",
            $disabled => "{$where} is disabled: it refuses every tap, and its arming is cleared.",
            default => "{$where} is enabled.",
        };
    }
}
