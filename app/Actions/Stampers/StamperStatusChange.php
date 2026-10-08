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
        $stamper = __('Stamper #:id at :business, :site', [
            'id' => $this->stamper->id,
            'business' => $this->stamper->business->name,
            'site' => SiteName::of($this->stamper->location),
        ]);
        $disabled = $this->stamper->status === StamperStatus::Disabled;

        return match (true) {
            ! $this->changed() && $disabled => __(':stamper was already disabled, perhaps by the business: leave it disabled after the re-key.', ['stamper' => $stamper]),
            ! $this->changed() => __(':stamper was already enabled.', ['stamper' => $stamper]),
            $disabled => __(':stamper is disabled: it refuses every tap, and its arming is cleared.', ['stamper' => $stamper]),
            default => __(':stamper is enabled.', ['stamper' => $stamper]),
        };
    }
}
