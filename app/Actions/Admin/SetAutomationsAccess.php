<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Features\Automations;
use App\Models\Business;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * The platform admin lets a business use automations, or stops it
 * (CHW-46, A1), until plans and billing decide it (CHW-52). Turned off, its
 * automations stay as the owner set them up and nothing is sent. Recorded in
 * the audit log when it changes; the same state again changes nothing,
 * checked under the business's lock, so a double click records it once.
 *
 * Locked FOR NO KEY UPDATE, as the status changes (ChangesBusinessStatus):
 * a tap or a stamp referencing the business is not stalled. An archived
 * business, or one in an archived organization, is refused.
 */
final readonly class SetAutomationsAccess
{
    public function __construct(private RecordAudit $recordAudit, private TenantContext $context) {}

    /**
     * @throws BusinessStatusRefused when the business is archived
     */
    public function handle(Business $business, bool $allowed): void
    {
        // Pennant stores the resolved value on a first read: done here, outside
        // the transaction, a concurrent first read cannot abort it on Postgres.
        Feature::for($business)->active(Automations::class);

        $this->context->bypass(fn () => DB::transaction(function () use ($business, $allowed): void {
            $locked = Business::query()->lock('for no key update')->findOrFail($business->id);

            if (! ArchivedSites::isOpen($locked->id, null)) {
                throw new BusinessStatusRefused(__(':business is archived.', ['business' => $locked->name]));
            }

            // Read again under the lock, not from this request's memory.
            Feature::flushCache();

            if (Feature::for($locked)->active(Automations::class) === $allowed) {
                return;
            }

            $allowed
                ? Feature::for($locked)->activate(Automations::class)
                : Feature::for($locked)->deactivate(Automations::class);

            $this->recordAudit->handle($allowed ? 'business.automations_allowed' : 'business.automations_stopped', $locked);
        }));
    }
}
