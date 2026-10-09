<?php

declare(strict_types=1);

namespace App\Actions\Admin\Concerns;

use App\Actions\Admin\BusinessStatusRefused;
use App\Models\Business;
use App\Support\Tenancy\ArchivedSites;

/**
 * What verifying, suspending and reinstating share (CHW-34): the business
 * row, locked and read fresh, refused when it or its organization is archived
 * (ArchivedSites' rule). FOR NO KEY UPDATE: the counter's FOR SHARE reads wait
 * for the change, but inserts referencing the business (a tap, a stamp) are
 * not stalled. Callers run inside TenantContext::bypass() and a transaction.
 */
trait ChangesBusinessStatus
{
    private function lockOpen(Business $business): Business
    {
        $locked = Business::query()->lock('for no key update')->findOrFail($business->id);

        if (! ArchivedSites::isOpen($locked->id, null)) {
            throw new BusinessStatusRefused(__(':business is archived.', ['business' => $locked->name]));
        }

        return $locked;
    }
}
