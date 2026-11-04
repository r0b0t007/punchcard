<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Features\Automations;
use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * The platform admin lets a business use automations, or stops it
 * (CHW-46, A1), until plans and billing decide it (CHW-52). Turned off, its
 * automations stay as the owner set them up and nothing is sent. Recorded in
 * the audit log when it changes; the same state again changes nothing.
 */
final readonly class SetAutomationsAccess
{
    public function __construct(private RecordAudit $recordAudit) {}

    public function handle(Business $business, bool $allowed): void
    {
        if (Feature::for($business)->active(Automations::class) === $allowed) {
            return;
        }

        DB::transaction(function () use ($business, $allowed): void {
            $allowed
                ? Feature::for($business)->activate(Automations::class)
                : Feature::for($business)->deactivate(Automations::class);

            $this->recordAudit->handle($allowed ? 'business.automations_allowed' : 'business.automations_stopped', $business);
        });
    }
}
