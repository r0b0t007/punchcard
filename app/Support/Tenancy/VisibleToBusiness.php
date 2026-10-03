<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Customer data a franchisee may see in part (ADR 0006): TenantScope asks the
 * model which of the organization's rows a business context without org
 * admin rights sees, instead of returning none.
 */
interface VisibleToBusiness
{
    /**
     * Narrows the organization's rows to those the business may see.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainToBusiness(Builder $query, int $businessId): void;
}
