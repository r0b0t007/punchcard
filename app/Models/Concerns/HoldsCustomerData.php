<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The customer data of a card program (enrollments, rewards): program data
 * of the organization, with the TenantScope's customerData rule (hidden
 * inside a business until PR C). Anyone in the tenant may create and update
 * it (the stamp and redeem Actions and their policies decide who), but the
 * columns that tie a row to its customer, card or milestone never change, and
 * deleting it is an erasure done by admin actions in bypass().
 *
 * @phpstan-require-extends Model
 */
trait HoldsCustomerData
{
    use BelongsToOrganization;

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        $name = class_basename($this);

        if ($operation === 'delete' && ! app(TenantContext::class)->isBypassed()) {
            throw new LogicException("{$name} is deleted only in TenantContext::bypass(), by an erasure.");
        }

        $fixed = array_intersect(array_keys($values), $this->immutableColumns());

        if ($fixed !== []) {
            throw new LogicException("{$name} ".implode(', ', $fixed).' cannot change.');
        }
    }

    /**
     * @return TenantScope<Model>
     */
    protected static function tenantScope(): TenantScope
    {
        return new TenantScope(customerData: true);
    }

    /**
     * The columns that never change once the row exists, even in bypass().
     *
     * @return list<string>
     */
    abstract protected function immutableColumns(): array;
}
