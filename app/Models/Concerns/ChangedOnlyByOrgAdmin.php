<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Program data that HQ owns (cards, the businesses that honour them): only an
 * org admin creates, changes or deletes it, in any context, so an independent
 * owner manages their card from inside their business while a franchisee
 * cannot change what the others share. Admin actions use bypass().
 *
 * @phpstan-require-extends Model
 */
trait ChangedOnlyByOrgAdmin
{
    use BelongsToOrganization {
        assertTenantInsert as assertProgramDataInsert;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertProgramDataInsert($values);
        $this->assertOrgAdminChangesProgram();
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        $this->assertOrgAdminChangesProgram();
    }

    private function assertOrgAdminChangesProgram(): void
    {
        $context = app(TenantContext::class);

        if (! $context->isBypassed() && ! $context->isOrgAdmin()) {
            throw new LogicException('Only an org admin changes the card program.');
        }
    }
}
