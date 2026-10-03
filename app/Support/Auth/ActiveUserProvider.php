<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The users the app may authenticate: never an anonymised account (CHW-139).
 * Every lookup goes through here (a session on any driver, login, remember
 * me, password reset), so an anonymised account cannot be signed in to,
 * stays signed out on every device, and gets no reset link, whatever else
 * changes around it.
 */
final class ActiveUserProvider extends EloquentUserProvider
{
    /**
     * @param  Model|null  $model
     * @return Builder<Model>
     */
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->whereNull('anonymised_at');
    }
}
