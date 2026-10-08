<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Actions\Stampers\SetStamperStatus;
use App\Actions\Stampers\SiteName;
use App\Actions\Stampers\StamperRefused;
use App\Enums\StamperStatus;

/**
 * What the disable and enable commands share: the Action, the refusal and
 * the line printed, which says when the stamper already had that status (the
 * business may have paused it: runbook step 1 asks you to note it).
 */
trait SetsStamperStatus
{
    private function setStatus(SetStamperStatus $setStamperStatus, StamperStatus $status): int
    {
        try {
            $change = $setStamperStatus->handle((string) $this->argument('uid'), $status);
        } catch (StamperRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $stamper = $change->stamper;
        $where = "Stamper #{$stamper->id} at {$stamper->business->name}, ".SiteName::of($stamper->location);

        $this->info(match (true) {
            ! $change->changed() && $status === StamperStatus::Disabled => "{$where} was already disabled, perhaps by the business: leave it disabled after the re-key.",
            ! $change->changed() => "{$where} was already enabled.",
            $status === StamperStatus::Disabled => "{$where} is disabled: it refuses every tap, and its arming is cleared.",
            default => "{$where} is enabled.",
        });

        return self::SUCCESS;
    }
}
