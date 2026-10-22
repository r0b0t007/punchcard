<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Database\Factories\BusinessDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A business verification document (CHW-145): the owner's ID or a proof of
 * the business's address. Site data: a franchisee reads its own, the org
 * admin the organization's. Every write is an Action's, in bypass()
 * (SubmitDocument; the platform's decision and the retention job): the
 * file and its row change together. The file is on the private `documents`
 * disk, encrypted with its own key (DocumentCipher, key_id); path is never
 * shown to anyone.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property DocumentKind $kind
 * @property string $path
 * @property string $key_id
 * @property string $mime
 * @property int $size
 * @property DocumentStatus $status
 * @property int|null $uploaded_by
 * @property string|null $rejection_reason
 * @property Carbon|null $decided_at
 * @property Carbon|null $deletes_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['path'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class BusinessDocument extends Model implements TenantModel
{
    use BelongsToBusiness {
        assertTenantInsert as assertSiteDataInsert;
    }
    use GuardsTenantWrites;

    /** @use HasFactory<BusinessDocumentFactory> */
    use HasFactory;

    /** The disk the files are on: local, never served by Laravel (no `serve`, no `url`). */
    public const string DISK = 'documents';

    /** @var array<string, mixed> The database default, also in memory before a refresh. */
    protected $attributes = ['status' => 'submitted'];

    /**
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('A verification document is sent through SubmitDocument, in TenantContext::bypass().');
        }

        $this->assertSiteDataInsert($values);
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('A verification document changes with its file, through its Actions, in TenantContext::bypass().');
        }
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'status' => DocumentStatus::class,
            'size' => 'integer',
            'decided_at' => 'datetime',
            'deletes_at' => 'datetime',
        ];
    }
}
