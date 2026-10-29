<?php

declare(strict_types=1);

namespace App\Actions\Stamps;

use App\Models\Stamper;

/**
 * Whether a stamper's arming still counts (CHW-29): armed, and whoever
 * armed it still works at its site. Taken off the staff, limited to another
 * location, or their account deleted, it gives one stamp like any tap
 * (ReceiveTap), and the counter shows the stamper idle (DescribeCounter,
 * CHW-309), so the two never disagree. An arming from before armers were
 * named (armed_by empty) counts.
 */
final readonly class ArmingCounts
{
    public function __construct(private WorksAt $worksAt) {}

    public function handle(Stamper $stamper): bool
    {
        return $stamper->isArmed()
            && ($stamper->armed_by === null || $this->worksAt->handle($stamper->armed_by, $stamper->business_id, $stamper->location_id));
    }
}
