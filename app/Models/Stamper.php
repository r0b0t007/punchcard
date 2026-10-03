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
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A stamper: an NFC tag assigned to a location of a business. Site data, so a
 * franchisee sees its own and the org admin the organization's. The tag's
 * uid, keys and replay counter live on NfcTag, which outlives the assignment.
 * An admin assigns a tag and ends or removes an assignment, in bypass(); the
 * tag of an assignment never changes, and an ended one (unassigned_at, one-way)
 * never claims the tag again. Moving a tag ends this assignment and adds one.
 * Status (the business pausing it), label, location and arming are
 * operational (CHW-22 policies); the tap endpoint uses current() stampers.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property int $location_id
 * @property int $nfc_tag_id
 * @property string|null $label
 * @property StamperStatus $status
 * @property int|null $armed_qty
 * @property Carbon|null $armed_until
 * @property Carbon|null $unassigned_at
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

    /**
     * An admin action assigns tags (CHW-138), in bypass().
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertSiteDataInsert($values);

        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('A tag is assigned by an admin action, in TenantContext::bypass().');
        }
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        if (array_key_exists('nfc_tag_id', $values)) {
            throw new LogicException('A stamper\'s tag cannot change: end this assignment and assign the tag again.');
        }

        if (app(TenantContext::class)->isBypassed()) {
            return;
        }

        if ($operation === 'delete') {
            throw new LogicException('A stamper is disabled, not deleted; admin actions delete in TenantContext::bypass().');
        }

        if (array_key_exists('unassigned_at', $values)) {
            throw new LogicException('Ending a tag\'s assignment is an admin action, in TenantContext::bypass().');
        }
    }

    /**
     * The tag's current assignment (paused or not), as opposed to ended ones.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull('unassigned_at');
    }

    /**
     * @return BelongsTo<NfcTag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(NfcTag::class, 'nfc_tag_id');
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
            'status' => StamperStatus::class,
            'armed_qty' => 'integer',
            'armed_until' => 'datetime',
            'unassigned_at' => 'datetime',
        ];
    }
}
