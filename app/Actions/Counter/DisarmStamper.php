<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use App\Models\Stamper;

/**
 * "Cancel" on an armed stamper (CHW-29, S1): its next tap gives one stamp
 * again. One statement, so a tap that already consumed the arming is left
 * as it was. The caller has checked StamperPolicy::arm.
 */
final readonly class DisarmStamper
{
    public function handle(Stamper $stamper): void
    {
        Stamper::query()->whereKey($stamper->id)->whereNotNull('armed_qty')->update(Stamper::DISARMED);
    }
}
