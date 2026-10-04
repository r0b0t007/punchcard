<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use LogicException;

/**
 * Query builder for platform data (IsPlatformData): NFC tags and the tap log,
 * no tenant's rows, written only by admin actions and the tap endpoint, in
 * TenantContext::bypass(). It refuses every write outside bypass(), model
 * saves and query-builder writes alike (bulk updates, increments, relation
 * updates such as $stamper->tag()->update()): a tag's moves are irreversible
 * (database triggers keep its counter and key version forward and its
 * retirement one-way), and the tap log is the replay and fraud record. Its
 * read guard (the platform scope) cannot be removed or replaced outside
 * bypass() either.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class PlatformBuilder extends Builder
{
    /** The read guard platform models register (IsPlatformData): rows read as empty outside bypass(). */
    public const string PLATFORM_SCOPE = 'platform';

    /**
     * @param  Scope<TModel>|string  $scope
     */
    public function withoutGlobalScope($scope): static
    {
        if ($scope === self::PLATFORM_SCOPE) {
            $this->assertPlatformAccess($this->name().' rows read as empty outside TenantContext::bypass(); the platform scope cannot be removed.');
        }

        return parent::withoutGlobalScope($scope);
    }

    /**
     * @param  string  $identifier
     * @param  Scope<TModel>|Closure  $scope
     */
    public function withGlobalScope($identifier, $scope): static
    {
        // Eloquent registers the model's own scope this way on every query; only a different one is a replacement.
        if ($identifier === self::PLATFORM_SCOPE && $scope !== ($this->model->getGlobalScopes()[self::PLATFORM_SCOPE] ?? null)) {
            $this->assertPlatformAccess($this->name().' rows read as empty outside TenantContext::bypass(); the platform scope cannot be replaced.');
        }

        return parent::withGlobalScope($identifier, $scope);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $this->assertPlatformWrite();

        return parent::update($values);
    }

    public function delete(): mixed
    {
        $this->assertPlatformWrite();

        return parent::delete();
    }

    public function forceDelete(): mixed
    {
        $this->assertPlatformWrite();

        return parent::forceDelete();
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int|string, mixed>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        $this->assertPlatformWrite();

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        $this->assertPlatformWrite();

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $this->assertPlatformWrite();

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        $this->assertPlatformWrite();

        return parent::incrementEach($columns, $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        $this->assertPlatformWrite();

        return parent::decrementEach($columns, $extra);
    }

    /**
     * @param  string|array<int, string>|null  $column
     */
    public function touch($column = null): false|int
    {
        $this->assertPlatformWrite();

        return parent::touch($column);
    }

    /**
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     */
    public function __call($method, $parameters): mixed
    {
        if (in_array(strtolower($method), TenantBuilder::UNGUARDED_WRITES, true)) {
            $this->assertPlatformWrite();
        }

        return parent::__call($method, $parameters);
    }

    private function assertPlatformWrite(): void
    {
        $this->assertPlatformAccess($this->name().' rows are platform state: only admin actions and the tap endpoint write them, in TenantContext::bypass().');
    }

    private function name(): string
    {
        return class_basename($this->model);
    }

    private function assertPlatformAccess(string $message): void
    {
        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException($message);
        }
    }
}
