<?php

declare(strict_types=1);

namespace App\Support\Nfc;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Query builder for NFC tags: platform state, written only by admin actions
 * and the tap endpoint, in TenantContext::bypass(). It refuses every write
 * outside bypass(), model saves and query-builder writes alike (bulk updates,
 * increments, relation updates such as $stamper->tag()->update()), because a
 * tag's moves are irreversible: database triggers keep its counter and key
 * version forward and its retirement one-way, so a stray write could kill a
 * tag for good.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class NfcTagBuilder extends Builder
{
    /** Writes Eloquent forwards to the base query (lowercase, as __call sees them). */
    private const array FORWARDED_WRITES = [
        'insert', 'insertgetid', 'insertorignore', 'insertorignorereturning',
        'insertusing', 'insertorignoreusing', 'updateorinsert', 'updatefrom', 'truncate',
    ];

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
        if (in_array(strtolower($method), self::FORWARDED_WRITES, true)) {
            $this->assertPlatformWrite();
        }

        return parent::__call($method, $parameters);
    }

    private function assertPlatformWrite(): void
    {
        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('NFC tags are platform state: admin actions and the tap endpoint write them, in TenantContext::bypass().');
        }
    }
}
