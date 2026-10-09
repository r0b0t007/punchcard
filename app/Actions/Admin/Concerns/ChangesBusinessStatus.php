<?php

declare(strict_types=1);

namespace App\Actions\Admin\Concerns;

use App\Actions\Admin\BusinessStatusRefused;
use App\Models\Business;

/**
 * What verifying, suspending and reinstating share (CHW-34): the business
 * row, locked FOR UPDATE and read fresh, refused when it or its organization
 * is archived. Callers run inside TenantContext::bypass() and a transaction.
 */
trait ChangesBusinessStatus
{
    private function lockOpen(Business $business): Business
    {
        $locked = Business::query()->with('organization')->lockForUpdate()->findOrFail($business->id);

        if ($locked->archived_at !== null || $locked->organization->archived_at !== null) {
            throw new BusinessStatusRefused(__(':business is archived.', ['business' => $locked->name]));
        }

        return $locked;
    }
}
