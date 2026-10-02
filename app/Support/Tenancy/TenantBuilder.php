<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use LogicException;

/**
 * Query builder for tenant models. The global scope decides which rows a
 * write touches; this decides what may be written, without relying on model
 * events (quiet saves, withoutEvents() and Event::fake() skip those):
 *
 * - a model's own insert is checked against the values actually inserted
 *   (TenantModel::assertTenantInsert), after any creating listener;
 * - tenant columns never change (update, increment, decrement, *Each),
 *   whatever their case or table prefix;
 * - updates and deletes also pass TenantModel::assertTenantWrite (who may
 *   change or delete the row, which columns need bypass());
 * - forceDelete() stays inside the scope;
 * - a loaded model's save or delete that matches no row in the tenant throws;
 * - raw inserts, upserts, updateOrInsert, updateFrom and truncate, which
 *   skip the model or the scope, need TenantContext::bypass().
 *
 * The tenant scope cannot be dropped or replaced outside bypass(), which also
 * covers relation rawUpdate()/touch(). getQuery() and getBaseQuery() return the
 * base builder and cannot be guarded here; tests/Unit/TenancyBoundaryTest.php
 * keeps them out of app code.
 *
 * Affected-row checks assume the database counts matched rows (SQLite and
 * Postgres do; MySQL needs PDO::MYSQL_ATTR_FOUND_ROWS).
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class TenantBuilder extends Builder
{
    private const array TENANT_COLUMNS = ['organization_id', 'business_id'];

    /** Raw writes that skip the model or the scope (lowercase, as Eloquent forwards them). */
    private const array UNGUARDED_WRITES = [
        'insert', 'insertgetid', 'insertorignore', 'insertorignorereturning',
        'insertusing', 'insertorignoreusing', 'updateorinsert', 'updatefrom', 'truncate',
    ];

    /** Model inserts: the methods Model::performInsert() calls. */
    private const array MODEL_INSERTS = ['insert', 'insertgetid'];

    /** Set by GuardsTenantWrites::performInsert() for this one insert. */
    private bool $expectingModelInsert = false;

    /** Set by GuardsTenantWrites::setKeysForSaveQuery() for one loaded model's update or delete. */
    private bool $expectingModelWrite = false;

    /** That model's primary key. */
    private mixed $modelWriteKey = null;

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $this->guardWrite('update', $values);

        return $this->checkModelWrite(parent::update($values));
    }

    public function delete(): mixed
    {
        $this->guardWrite('delete', []);

        $deleted = parent::delete();

        return is_int($deleted) ? $this->checkModelWrite($deleted) : $deleted;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int|string, mixed>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        $this->guardRawWrite('upsert');

        $firstRow = reset($values);
        $updated = $update ?? (is_array($firstRow) ? array_keys($firstRow) : array_keys($values));
        $columns = [];

        // $update mixes column names (list values) and column => expression pairs.
        foreach ($updated as $key => $value) {
            $columns[is_int($key) ? (string) $value : $key] = null;
        }

        $this->guardTenantColumns($columns);

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * Eloquent's forceDelete() deletes through the unscoped base query; this
     * keeps it inside the tenant scope like delete().
     */
    public function forceDelete(): mixed
    {
        $this->guardWrite('delete', []);

        return $this->toBase()->delete();
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        $this->guardWrite('update', [$column => $amount] + $extra);

        return $this->checkModelWrite(parent::increment($column, $amount, $extra));
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $this->guardWrite('update', [$column => $amount] + $extra);

        return $this->checkModelWrite(parent::decrement($column, $amount, $extra));
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        $this->guardWrite('update', $columns + $extra);

        return $this->checkModelWrite(parent::incrementEach($columns, $extra));
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        $this->guardWrite('update', $columns + $extra);

        return $this->checkModelWrite(parent::decrementEach($columns, $extra));
    }

    /**
     * Only GuardsTenantWrites::performInsert() calls this, right before the
     * model's insert; the insert is then checked by value, not trusted.
     *
     * @internal
     */
    public function expectModelInsert(): void
    {
        $this->expectingModelInsert = true;
    }

    /**
     * Only GuardsTenantWrites::setKeysForSaveQuery() calls this: the next
     * update or delete targets one loaded model and must find it in the tenant.
     *
     * @internal
     */
    public function expectModelWrite(mixed $key): void
    {
        $this->expectingModelWrite = true;
        $this->modelWriteKey = $key;
    }

    /**
     * @param  string|Scope<TModel>  $scope
     */
    public function withoutGlobalScope($scope): static
    {
        $identifier = is_object($scope) ? $scope::class : $scope;

        if ($identifier === TenantScope::class && ! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('The tenant scope of '.class_basename($this->model).' can only be removed in TenantContext::bypass().');
        }

        return parent::withoutGlobalScope($scope);
    }

    /**
     * @param  string  $identifier
     * @param  Scope<TModel>|\Closure  $scope
     */
    public function withGlobalScope($identifier, $scope): static
    {
        // Only the model's own tenant scope may be (re)applied, e.g. by GuardsTenantWrites.
        if ($identifier === TenantScope::class
            && $scope !== ($this->model->getGlobalScopes()[TenantScope::class] ?? null)
            && ! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('The tenant scope of '.class_basename($this->model).' cannot be replaced.');
        }

        return parent::withGlobalScope($identifier, $scope);
    }

    /**
     * @param  string|array<int, string>|null  $column
     */
    public function touch($column = null): false|int
    {
        $columns = $column === null ? [(string) $this->model->getUpdatedAtColumn()] : (array) $column;
        $this->guardWrite('update', array_fill_keys($columns, null));

        return parent::touch($column);
    }

    /**
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     */
    public function __call($method, $parameters): mixed
    {
        $name = strtolower($method);

        if ($this->expectingModelInsert && in_array($name, self::MODEL_INSERTS, true)) {
            $this->expectingModelInsert = false;
            $this->assertModelInsert($parameters[0] ?? []);
        } elseif (in_array($name, self::UNGUARDED_WRITES, true)) {
            $this->guardRawWrite($method);

            if ($name === 'updateorinsert' && is_array($parameters[1] ?? null)) {
                $this->guardTenantColumns($parameters[1]);
            }
        }

        return parent::__call($method, $parameters);
    }

    private function assertModelInsert(mixed $values): void
    {
        if (! $this->model instanceof TenantModel || ! is_array($values)) {
            throw new LogicException('Tenant inserts need a TenantModel and a single row.');
        }

        /** @var array<string, mixed> $values */
        $this->model->assertTenantInsert($values);
    }

    /**
     * Tenant columns never change; then the model decides who may write.
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    private function guardWrite(string $operation, array $values): void
    {
        $columns = [];

        foreach ($values as $column => $value) {
            $name = strtolower((string) $column);
            $columns[substr($name, (int) strrpos('.'.$name, '.'))] = $value;
        }

        $this->guardTenantColumns($columns);

        if (! $this->model instanceof TenantModel) {
            throw new LogicException('Tenant writes need a TenantModel.');
        }

        $this->model->assertTenantWrite($operation, $columns);
    }

    /**
     * Tenant columns never change, whatever their case or table prefix.
     *
     * @param  array<array-key, mixed>  $values
     */
    private function guardTenantColumns(array $values): void
    {
        foreach (array_keys($values) as $column) {
            $name = strtolower((string) $column);

            if (in_array(substr($name, (int) strrpos('.'.$name, '.')), self::TENANT_COLUMNS, true)) {
                throw new LogicException('The tenant of '.class_basename($this->model).' cannot change.');
            }
        }
    }

    /** A loaded model's save or delete that matched no row in the tenant targeted another tenant's row. */
    private function checkModelWrite(int $affected): int
    {
        $expected = $this->expectingModelWrite;
        $this->expectingModelWrite = false;

        if ($expected && $affected === 0 && ! app(TenantContext::class)->isBypassed() && $this->modelRowExists()) {
            throw new LogicException(class_basename($this->model).' is outside the current tenant.');
        }

        return $affected;
    }

    /** Whether the loaded model's row still exists in any tenant (a deleted row is simply gone). */
    private function modelRowExists(): bool
    {
        return app(TenantContext::class)->bypass(
            fn (): bool => $this->model->newQueryWithoutScopes()->whereKey($this->modelWriteKey)->exists(),
        );
    }

    private function guardRawWrite(string $method): void
    {
        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException($method.'() on '.class_basename($this->model).' skips the tenant guards; save models, or run it in TenantContext::bypass().');
        }
    }
}
