<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Actions\Stampers\SetStamperStatus;
use App\Actions\Stampers\SiteName;
use App\Actions\Stampers\StamperRefused;
use App\Enums\StamperStatus;

/** What the disable and enable commands share: the Action, the refusal and the line printed. */
trait SetsStamperStatus
{
    private function setStatus(SetStamperStatus $setStamperStatus, StamperStatus $status): int
    {
        try {
            $stamper = $setStamperStatus->handle((string) $this->argument('uid'), $status);
        } catch (StamperRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info("Stamper #{$stamper->id} at {$stamper->business->name}, ".SiteName::of($stamper->location).' is '.($status === StamperStatus::Active ? 'enabled' : 'disabled: it refuses every tap').'.');

        return self::SUCCESS;
    }
}
