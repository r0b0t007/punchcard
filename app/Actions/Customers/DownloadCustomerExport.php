<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Actions\Admin\RecordAudit;
use App\Enums\BusinessRole;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * An owner downloads their export (CHW-49): still an owner of the business, the file still there (a day at most,
 * DeleteExpiredExports). Recorded in the audit log before any byte goes out; the zip decrypted for them alone.
 */
final readonly class DownloadCustomerExport
{
    public function __construct(private TenantContext $context, private RecordAudit $recordAudit) {}

    /** Whether the user still owns the business (a removed or demoted owner's link no longer opens). */
    public function owns(User $user, int $businessId): bool
    {
        return $this->context->bypass(fn (): bool => BusinessMember::query()->where('business_id', $businessId)
            ->where('user_id', $user->id)->where('role', BusinessRole::Owner)->exists());
    }

    public function exists(int $businessId, string $export): bool
    {
        return Storage::disk(BuildCustomerExport::DISK)->exists(BuildCustomerExport::path($businessId, $export));
    }

    /** The zip's bytes, the download recorded first. */
    public function handle(User $owner, int $businessId, string $export): string
    {
        $business = $this->context->bypass(fn (): Business => Business::query()->findOrFail($businessId));

        DB::transaction(fn (): AuditLog => $this->recordAudit->handle('customers.export_downloaded', $business, context: ['export' => $export], actor: $owner));

        return Crypt::decryptString((string) Storage::disk(BuildCustomerExport::DISK)->get(BuildCustomerExport::path($businessId, $export)));
    }
}
