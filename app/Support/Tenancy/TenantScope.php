<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters a tenant model to the current TenantContext, failing closed.
 *
 * Organization-level models (program data, and the organization itself)
 * filter on the organization. Business-level models (site data, and the
 * business itself) filter on the business inside a business context, and on
 * the organization for an org admin. No tenant: no rows.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final readonly class TenantScope implements Scope
{
    /**
     * @param  string|null  $businessColumn  the column holding the business id; null for organization-level models
     * @param  string  $organizationColumn  the column holding the organization id
     */
    public function __construct(
        private ?string $businessColumn = null,
        private string $organizationColumn = 'organization_id',
    ) {}

    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $organizationId = $context->organizationId();
        $businessId = $context->businessId();

        if ($this->businessColumn !== null && $businessId !== null) {
            $builder->where($model->qualifyColumn($this->businessColumn), $businessId);

            return;
        }

        if ($organizationId !== null) {
            $builder->where($model->qualifyColumn($this->organizationColumn), $organizationId);

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
