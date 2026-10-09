<?php

declare(strict_types=1);

namespace App\Actions\Admin\Concerns;

use App\Actions\Admin\BusinessStatusRefused;
use App\Models\Business;
use App\Models\Stamper;
use App\Support\Tenancy\ArchivedSites;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * What verifying, suspending and reinstating share (CHW-34): one status
 * transition, in bypass() and a transaction, recorded in the audit log in
 * it. The using class has a TenantContext $context and a RecordAudit
 * $recordAudit.
 *
 * The business row is locked FOR NO KEY UPDATE and read fresh: the counter's
 * FOR SHARE reads wait for the change, and inserts referencing the business
 * (a tap, a stamp) are not stalled. An archived business, or one in an
 * archived organization, is refused (ArchivedSites' rule).
 */
trait ChangesBusinessStatus
{
    /**
     * @param  Closure(Business): void  $guard  throws BusinessStatusRefused when the change does not apply
     * @param  Closure(Business): array<string, mixed>  $fields  the columns to write, from the locked row
     * @param  bool  $lockStampers  lock the business's current stampers first and again after the
     *                              business row (see SuspendBusiness)
     */
    private function transition(Business $business, Closure $guard, Closure $fields, string $action, ?string $reason = null, bool $lockStampers = false): Business
    {
        return $this->context->bypass(fn (): Business => DB::transaction(function () use ($business, $guard, $fields, $action, $reason, $lockStampers): Business {
            if ($lockStampers) {
                $this->lockStampers($business);
            }

            $locked = Business::query()->lock('for no key update')->findOrFail($business->id);

            if ($lockStampers) {
                $this->lockStampers($business);
            }

            if (! ArchivedSites::isOpen($locked->id, null)) {
                throw new BusinessStatusRefused(__(':business is archived.', ['business' => $locked->name]));
            }

            $guard($locked);
            $locked->forceFill($fields($locked))->save();
            $this->recordAudit->handle($action, $locked, $reason);

            return $locked;
        }));
    }

    private function lockStampers(Business $business): void
    {
        Stamper::query()->current()->where('business_id', $business->id)->inLockOrder()->pluck('id');
    }
}
