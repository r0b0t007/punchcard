<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StamperStatus;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Database\Factories\StamperFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An NFC stamper at a location: site data, so a franchisee sees its own and
 * the org admin the organization's. Its identity and replay state belong to
 * the platform: an admin registers it and re-provisions it (key_version), the
 * tap endpoint advances last_counter under a row lock, all in bypass(); the
 * uid never changes and a database trigger keeps last_counter from going
 * back. Status, label, location and arming are operational (CHW-22 policies).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property int $location_id
 * @property string $uid
 * @property string|null $label
 * @property int $key_version
 * @property int $last_counter
 * @property StamperStatus $status
 * @property int|null $armed_qty
 * @property Carbon|null $armed_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['label', 'location_id'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class Stamper extends Model implements TenantModel
{
    use BelongsToBusiness {
        assertTenantInsert as assertSiteDataInsert;
    }
    use GuardsTenantWrites;

    /** @use HasFactory<StamperFactory> */
    use HasFactory;

    /** Columns only the platform changes, in bypass(): the keys and the replay guard. */
    private const array PLATFORM_COLUMNS = ['key_version', 'last_counter'];

    /**
     * Stampers are registered by an admin action (CHW-138), which holds the
     * tag's keys, in bypass().
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertSiteDataInsert($values);

        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('Stampers are registered by an admin action, in TenantContext::bypass().');
        }
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        if (array_key_exists('uid', $values)) {
            throw new LogicException('A stamper uid cannot change: replace the stamper instead.');
        }

        if (app(TenantContext::class)->isBypassed()) {
            return;
        }

        if ($operation === 'delete') {
            throw new LogicException('A stamper is disabled, not deleted; admin actions delete in TenantContext::bypass().');
        }

        if (array_intersect(array_keys($values), self::PLATFORM_COLUMNS) !== []) {
            throw new LogicException('Stamper keys and counter change only in the tap endpoint and re-provisioning, in TenantContext::bypass().');
        }
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key_version' => 'integer',
            'last_counter' => 'integer',
            'status' => StamperStatus::class,
            'armed_qty' => 'integer',
            'armed_until' => 'datetime',
        ];
    }
}
