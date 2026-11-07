<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Carbon\CarbonInterface;

/**
 * A moment filled in once on a row that is otherwise kept as written (an
 * automation's or a campaign's send: when a phone accepted it, when it was
 * first opened): a conditional update, so a retry or another worker never
 * moves it, and the model follows without being left dirty.
 */
trait FillsOnce
{
    /** Whether this call filled it in: false when it already was. */
    protected function fillOnce(string $column, CarbonInterface $at): bool
    {
        if (static::query()->whereKey($this->getKey())->whereNull($column)->update([$column => $at]) !== 1) {
            return false;
        }

        $this->forceFill([$column => $at])->syncOriginalAttribute($column);

        return true;
    }
}
