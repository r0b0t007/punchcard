<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Actions\Admin\RecordAudit;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;

/**
 * An owner downloads their export (CHW-49), once BusinessCustomerPolicy::downloadExport has let them: the file read
 * and decrypted first (a day at most, DeleteExpiredExports), then the download recorded in the audit log, before any
 * byte goes out. A file already gone is null, and nothing is recorded.
 */
final readonly class DownloadCustomerExport
{
    public function __construct(private TenantContext $context, private RecordAudit $recordAudit) {}

    /** The zip's bytes, the download recorded; null once the file is gone. */
    public function handle(User $owner, int $businessId, string $export): ?string
    {
        try {
            $encrypted = Storage::disk(BuildCustomerExport::DISK)->get(BuildCustomerExport::path($businessId, $export));
        } catch (UnableToReadFile) {
            $encrypted = null;
        }

        if ($encrypted === null) {
            return null;
        }

        $zip = Crypt::decryptString($encrypted);
        $business = $this->context->bypass(fn (): Business => Business::query()->findOrFail($businessId));

        DB::transaction(fn (): AuditLog => $this->recordAudit->handle('customers.export_downloaded', $business, context: ['export' => $export], actor: $owner));

        return $zip;
    }
}
