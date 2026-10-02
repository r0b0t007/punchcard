<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use LogicException;

/**
 * A model whose rows belong to a tenant (ADR 0006). TenantBuilder calls these
 * for every insert of the model, events or not, so quiet saves,
 * withoutEvents() and Event::fake() cannot skip them.
 */
interface TenantModel
{
    /** Fills missing tenant columns from the TenantContext before inserting. */
    public function fillTenantColumns(): void;

    /**
     * Checks the values about to be inserted against the TenantContext.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws LogicException when the row would belong to another tenant, or no tenant is set
     */
    public function assertTenantInsert(array $values): void;

    /**
     * Checks an update or delete of this model's rows (bulk, or one loaded
     * model) beyond the scope: who may change or delete it, and which columns
     * need bypass().
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values  the columns being updated, lower-cased and unqualified
     *
     * @throws LogicException when the current tenant may not do it
     */
    public function assertTenantWrite(string $operation, array $values): void;
}
