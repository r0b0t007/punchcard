<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Program data (cards, enrollments, rewards, org-wide campaigns): shared by
 * every business of the organization, so a franchise's franchisees see the
 * same card, and scoped to the current organization (ADR 0006).
 *
 * Inserts fail closed like reads: they need a tenant or bypass().
 * organization_id comes from the context when missing and must match it. Use
 * with GuardsTenantWrites and #[UseEloquentBuilder(TenantBuilder::class)],
 * which run these checks on every insert and keep organization_id from ever
 * changing.
 *
 * @phpstan-require-extends Model
 *
 * @property int $organization_id
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function fillTenantColumns(): void
    {
        if ($this->getAttribute('organization_id') === null) {
            $this->setAttribute('organization_id', app(TenantContext::class)->organizationId());
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $context = app(TenantContext::class);
        $bypassed = $context->isBypassed();
        $name = class_basename($this);
        $organizationId = $values['organization_id'] ?? null;

        if (! $bypassed && $context->organizationId() === null) {
            throw new LogicException("Creating {$name} needs a tenant, or TenantContext::bypass().");
        }

        if ($organizationId === null) {
            throw new LogicException("{$name} needs an organization.");
        }

        if (! $bypassed && (int) $organizationId !== $context->organizationId()) {
            throw new LogicException("Cannot write {$name} for another organization.");
        }
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void {}

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The card program (cards and the businesses that honour them) is HQ's: only
     * an org admin changes it, so a franchisee cannot change the card the others
     * share. Billing and admin actions use bypass().
     */
    protected function assertOrgAdminChangesProgram(): void
    {
        $context = app(TenantContext::class);

        if (! $context->isBypassed() && ! $context->isOrgAdmin()) {
            throw new LogicException('Only an org admin changes the card program.');
        }
    }
}
