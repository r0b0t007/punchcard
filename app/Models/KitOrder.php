<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KitOrderStatus;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Database\Factories\KitOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Where to ship a business's stamper kit (CHW-31): site data, so a
 * franchisee sees its own, the org admin the organization's. The owner
 * requests it and may fix the address while it is only requested; its
 * status is the platform's fulfilment (CHW-57), in bypass(). One requested order per business.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property int|null $location_id
 * @property string $recipient_name
 * @property string $phone
 * @property string $address
 * @property string $city
 * @property string|null $postal_code
 * @property string $country
 * @property KitOrderStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['location_id', 'recipient_name', 'phone', 'address', 'city', 'postal_code'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class KitOrder extends Model implements TenantModel
{
    use BelongsToBusiness {
        assertTenantInsert as assertSiteDataInsert;
    }
    use GuardsTenantWrites;

    /** @use HasFactory<KitOrderFactory> */
    use HasFactory;

    /** @var array<string, mixed> The database defaults, also in memory before a refresh. */
    protected $attributes = ['status' => 'requested', 'country' => 'MA'];

    /**
     * Requested by the owner at an open site; any other status is fulfilment's.
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertSiteDataInsert($values);
        ArchivedSites::assertOpen($values['business_id'] ?? null, $values['location_id'] ?? null, 'A kit order', lock: true);

        $status = $values['status'] ?? KitOrderStatus::Requested->value;

        if (($status instanceof KitOrderStatus ? $status : KitOrderStatus::tryFrom((string) $status)) !== KitOrderStatus::Requested && ! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('A kit order is requested; its status moves with fulfilment, in TenantContext::bypass().');
        }
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        if (app(TenantContext::class)->isBypassed()) {
            return;
        }

        if ($operation === 'delete') {
            throw new LogicException('A kit order is kept: fulfilment cancels it, in TenantContext::bypass().');
        }

        if (array_key_exists('status', $values)) {
            throw new LogicException('A kit order\'s status moves with fulfilment, in TenantContext::bypass().');
        }

        if ($this->exists && $this->getRawOriginal('status') !== KitOrderStatus::Requested->value) {
            throw new LogicException('Only a requested kit order can be corrected: fulfilment has it now.');
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => KitOrderStatus::class,
        ];
    }
}
