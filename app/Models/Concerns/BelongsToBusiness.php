<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Business;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Site data (locations, stampers, staff, business campaigns): scoped to the
 * current business, or to the organization for an org admin (ADR 0006).
 *
 * Inserts fail closed like reads: they need a tenant or bypass(). business_id
 * comes from the context when missing and must match it; organization_id is
 * always the business's own. Use with GuardsTenantWrites and
 * #[UseEloquentBuilder(TenantBuilder::class)], which run these checks on
 * every insert and keep the tenant columns from ever changing.
 *
 * @phpstan-require-extends Model
 *
 * @property int $business_id
 * @property int $organization_id
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new TenantScope('business_id'));
    }

    public function fillTenantColumns(): void
    {
        $context = app(TenantContext::class);

        if ($this->getAttribute('business_id') === null && $context->businessId() !== null) {
            $this->setAttribute('business_id', $context->businessId());
        }

        if ($this->getAttribute('organization_id') === null && $this->getAttribute('business_id') !== null) {
            $this->setAttribute('organization_id', $this->organizationOfBusiness($this->getAttribute('business_id')));
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
        $businessId = $values['business_id'] ?? null;

        if (! $bypassed && $context->organizationId() === null) {
            throw new LogicException("Creating {$name} needs a tenant, or TenantContext::bypass().");
        }

        if ($businessId === null) {
            throw new LogicException("{$name} needs a business.");
        }

        if (! $bypassed && $context->businessId() !== null && (int) $businessId !== $context->businessId()) {
            throw new LogicException("Cannot write {$name} for another business.");
        }

        $organizationId = $this->organizationOfBusiness($businessId);

        if ($organizationId === null
            || (! $bypassed && $organizationId !== $context->organizationId())
            || (int) ($values['organization_id'] ?? 0) !== $organizationId) {
            throw new LogicException("Cannot write {$name} for another organization.");
        }
    }

    /**
     * Site data has no extra write rules: the scope already limits a franchisee
     * to their business and an org admin to the organization.
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void {}

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    private function organizationOfBusiness(mixed $businessId): ?int
    {
        $organizationId = app(TenantContext::class)->bypass(
            fn (): mixed => Business::query()->whereKey($businessId)->value('organization_id'),
        );

        return $organizationId === null ? null : (int) $organizationId;
    }
}
