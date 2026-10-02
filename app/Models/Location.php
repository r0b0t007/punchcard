<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantModel;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'business_id', 'name', 'address', 'lat', 'lng', 'timezone'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class Location extends Model implements TenantModel
{
    use BelongsToBusiness;
    use GuardsTenantWrites;

    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
        ];
    }
}
