<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Actions\Admin\RecordAudit;
use App\Jobs\ExportCustomers;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * An owner asks for their customers and stamps as CSV (CHW-49, design 21): recorded in the audit log (who, which
 * business, which days), then made on the queue in the business's tenant (ExportCustomers), which emails them a link.
 * The record and the job go together: the job is only queued once the record is committed. PER_HOUR exports an hour
 * per owner; only a valid request counts, so fixing the dates never uses one up.
 */
final readonly class RequestCustomerExport
{
    public const int PER_HOUR = 3;

    public function __construct(private RecordAudit $recordAudit) {}

    /**
     * @throws ValidationException when the owner has asked PER_HOUR times this hour
     */
    public function handle(Business $business, User $owner, string $from, string $to): void
    {
        if (RateLimiter::hit('customer-export:'.$owner->id, 3600) > self::PER_HOUR) {
            throw ValidationException::withMessages(['from' => __('Too many exports asked for. Try again in an hour.')]);
        }

        DB::transaction(function () use ($business, $owner, $from, $to): void {
            $this->recordAudit->handle('customers.export_requested', $business, context: ['from' => $from, 'to' => $to], actor: $owner);

            ExportCustomers::dispatch($business->id, $owner->id, $from, $to)->afterCommit();
        });
    }
}
