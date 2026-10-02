<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BusinessStatus;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use App\Support\Tenancy\TenantScope;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The site-level tenant: a legal entity running locations, staff and stampers
 * (an independent café, or one franchisee). Scoped like site data: an owner or
 * staff member sees their own business, an org admin every business of the
 * organization, and nobody else anything (ADR 0006). Ownership is a
 * business_user membership with role owner.
 *
 * status and plan are not mass assignable: verification and billing actions
 * set them explicitly.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property string|null $category
 * @property BusinessStatus $status
 * @property string|null $plan
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 */
#[Fillable(['organization_id', 'name', 'slug', 'category'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class Business extends Model implements TenantModel
{
    use GuardsTenantWrites;

    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope('id'));
    }

    public function fillTenantColumns(): void
    {
        $this->organization_id ??= app(TenantContext::class)->organizationId();
    }

    /**
     * Only an org admin (organization context, no business) or bypass() creates
     * a business, and only in their own organization: a franchisee cannot add
     * a sibling business.
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $context = app(TenantContext::class);

        if (! isset($values['organization_id'])) {
            throw new LogicException('Creating a business needs a tenant, or TenantContext::bypass().');
        }

        if ($context->isBypassed()) {
            return;
        }

        if ($context->businessId() !== null) {
            throw new LogicException('Only an org admin can create a business.');
        }

        $status = $values['status'] ?? BusinessStatus::Pending->value;

        if (($status instanceof BusinessStatus ? $status : BusinessStatus::tryFrom((string) $status)) !== BusinessStatus::Pending || ($values['plan'] ?? null) !== null) {
            throw new LogicException('A new business starts pending and without a plan; verification and billing actions change them, in TenantContext::bypass().');
        }

        if ($context->organizationId() === null || (int) $values['organization_id'] !== $context->organizationId()) {
            throw new LogicException('Cannot create a business in another organization.');
        }
    }

    /**
     * A franchisee may update their own business, but not delete it, and
     * status and plan change only through verification and billing actions
     * (bypass()). An org admin may delete businesses of the organization.
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        if ($operation === 'delete' && $context->businessId() !== null) {
            throw new LogicException('Only an org admin can delete a business.');
        }

        if (array_intersect(array_keys($values), ['status', 'plan']) !== []) {
            throw new LogicException('Business status and plan change through verification and billing actions, in TenantContext::bypass().');
        }
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BusinessStatus::class,
        ];
    }
}
