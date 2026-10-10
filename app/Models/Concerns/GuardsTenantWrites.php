<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use App\Support\Tenancy\TenantScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * For tenant models built with #[UseEloquentBuilder(TenantBuilder::class)].
 * Runs whether or not model events fire:
 *
 * - inserts: fills the tenant columns, then TenantBuilder checks the values
 *   actually inserted;
 * - saveOrIgnore(), which inserts through the base builder, needs bypass();
 * - saving or deleting a loaded model runs inside the tenant scope and
 *   throws when the row is outside the current tenant (Eloquent would
 *   otherwise write by primary key alone).
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements TenantModel
 */
trait GuardsTenantWrites
{
    /** The key the write being guarded targets when it is a loaded model's own save; null otherwise. */
    private mixed $tenantWriteTarget = null;

    /**
     * Runs a write's guard knowing what it targets (TenantBuilder): the key of
     * the model saving itself, or null for a bulk write or a query built from
     * a model, which may reach any row in the tenant.
     *
     * @internal
     */
    public function guardTenantWrite(mixed $target, Closure $guard): void
    {
        $this->tenantWriteTarget = $target;

        try {
            $guard();
        } finally {
            $this->tenantWriteTarget = null;
        }
    }

    /** Whether the write being guarded is this loaded model saving itself, not a query reaching other rows. */
    protected function isOwnTenantWrite(): bool
    {
        return $this->exists && $this->tenantWriteTarget !== null;
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function performInsert(Builder $query): bool
    {
        $this->fillTenantColumns();

        if ($query instanceof TenantBuilder) {
            $query->expectModelInsert();
        }

        return parent::performInsert($query);
    }

    /**
     * @param  Builder<static>  $query
     * @param  array<int, string>|string|null  $uniqueBy
     */
    protected function performInsertOrIgnore(Builder $query, array|string|null $uniqueBy): bool
    {
        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('saveOrIgnore() on '.class_basename($this).' skips the tenant guards; use save(), or TenantContext::bypass().');
        }

        $this->fillTenantColumns();
        $this->assertTenantInsert($this->getAttributes());

        return parent::performInsertOrIgnore($query, $uniqueBy);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query): Builder
    {
        // Only the tenant scope: soft-delete or other scopes would hide rows a save may target.
        foreach ($this->getGlobalScopes() as $identifier => $scope) {
            if ($scope instanceof TenantScope) {
                $query->withGlobalScope($identifier, $scope);
            }
        }

        if ($query instanceof TenantBuilder) {
            $query->expectModelWrite($this->getKeyForSaveQuery());
        }

        parent::setKeysForSaveQuery($query);

        return $query;
    }
}
