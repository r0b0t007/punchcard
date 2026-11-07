<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Actions\Admin\RecordAudit;
use App\Jobs\ExportCustomers;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An owner asks for their customers and stamps as CSV (CHW-49, design 21): recorded in the audit log (who, which
 * business, which days), then made on the queue in the business's tenant (ExportCustomers), which emails them a link.
 * The record and the job go together: the job is only queued once the record is committed.
 */
final readonly class RequestCustomerExport
{
    public function __construct(private RecordAudit $recordAudit) {}

    public function handle(Business $business, User $owner, string $from, string $to): void
    {
        DB::transaction(function () use ($business, $owner, $from, $to): void {
            $this->recordAudit->handle('customers.export_requested', $business, context: ['from' => $from, 'to' => $to], actor: $owner);

            ExportCustomers::dispatch($business->id, $owner->id, $from, $to)->afterCommit();
        });
    }
}
