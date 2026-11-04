<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Features\Automations;
use App\Models\Business;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * The platform admin lets a business use automations, or stops it
 * (CHW-46, A1), until plans and billing decide it (CHW-52). Turned off, its
 * automations stay as the owner set them up and nothing is sent. Recorded in
 * the audit log when it changes; the same state again changes nothing,
 * checked under the business's lock, so a double click records it once.
 */
final readonly class SetAutomationsAccess
{
    public function __construct(private RecordAudit $recordAudit, private TenantContext $context) {}

    public function handle(Business $business, bool $allowed): void
    {
        DB::transaction(function () use ($business, $allowed): void {
            $this->context->bypass(fn () => Business::query()->whereKey($business->id)->lockForUpdate()->first());
            // Read again under the lock, not from this request's memory.
            Feature::flushCache();

            if (Feature::for($business)->active(Automations::class) === $allowed) {
                return;
            }

            $allowed
                ? Feature::for($business)->activate(Automations::class)
                : Feature::for($business)->deactivate(Automations::class);

            $this->recordAudit->handle($allowed ? 'business.automations_allowed' : 'business.automations_stopped', $business);
        });
    }
}
