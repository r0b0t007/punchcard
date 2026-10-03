<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A physical site of a business: a map pin with its own timezone and stampers.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property string $name
 * @property string|null $address
 * @property string|null $lat
 * @property string|null $lng
 * @property string $timezone
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'business_id', 'name', 'address', 'lat', 'lng', 'timezone'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class Location extends Model implements TenantModel
{
    use BelongsToBusiness {
        assertTenantInsert as assertSiteDataInsert;
    }
    use GuardsTenantWrites;

    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    /**
     * A location is created open, at a business that is not archived (also in
     * bypass()); imports may bring an archived one, in bypass().
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertSiteDataInsert($values);
        ArchivedSites::assertOpen($values['business_id'] ?? null, null, 'A location', lock: true);

        if (($values['archived_at'] ?? null) !== null && ! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('A location is archived by ArchiveLocation, in TenantContext::bypass().');
        }
    }

    /**
     * Archiving and restoring a location go through ArchiveLocation and the
     * platform admin (RestoreArchived), in bypass(): they end its stampers too.
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        if (array_key_exists('archived_at', $values) && ! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('A location is archived or restored by ArchiveLocation and the platform admin, in TenantContext::bypass().');
        }
    }

    /**
     * Locations still open: what maps, stamper lists and new assignments use.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'archived_at' => 'datetime',
        ];
    }
}
