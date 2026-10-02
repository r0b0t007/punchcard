<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingEntity;
use App\Enums\OrganizationType;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use App\Support\Tenancy\TenantScope;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The brand and card program owner (ADR 0006). Scoped to the current
 * organization: a tenant never reads another organization's brand, plan or
 * billing. Public lookups (the tap endpoint, a white-label domain) use bypass().
 *
 * Type, billing and plan fields (type, plan, billing_entity, white_label) are
 * not mass assignable; signup, billing and admin actions set them explicitly.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property OrganizationType $type
 * @property string|null $logo_path
 * @property string|null $brand_color
 * @property string|null $cover_path
 * @property BillingEntity $billing_entity
 * @property string|null $plan
 * @property bool $white_label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'logo_path', 'brand_color', 'cover_path'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class Organization extends Model implements TenantModel
{
    use GuardsTenantWrites;

    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope(organizationColumn: 'id'));
    }

    public function fillTenantColumns(): void {}

    /**
     * Organizations are created by signup or admin actions, inside bypass().
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('Organizations are created by signup or admin actions, inside TenantContext::bypass().');
        }
    }

    /**
     * Only the org admin (organization context, no business) changes the
     * organization; a franchisee cannot rename or rebrand it for the others.
     * Type, plan, billing and white-label change only in billing and admin
     * actions, and deleting an organization only in bypass().
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

        if ($operation === 'delete') {
            throw new LogicException('Organizations are deleted by admin actions, in TenantContext::bypass().');
        }

        // A franchisee cannot change the brand the other businesses share. With a single
        // business (an independent café, a one-company chain), business context covers it.
        // Whether that user is the owner or staff is a policy question (CHW-22).
        if ($context->businessId() !== null && $this->businessCount() > 1) {
            throw new LogicException('Only an org admin can change the organization.');
        }

        if (array_intersect(array_keys($values), ['type', 'plan', 'billing_entity', 'white_label']) !== []) {
            throw new LogicException('Organization type, plan and billing change through billing and admin actions, in TenantContext::bypass().');
        }
    }

    /**
     * @return HasMany<Business, $this>
     */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    /** How many businesses the current organization has (bulk writes have no loaded model to ask). */
    private function businessCount(): int
    {
        $context = app(TenantContext::class);

        return $context->bypass(fn (): int => Business::query()->where('organization_id', $context->organizationId())->count());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'billing_entity' => BillingEntity::class,
            'white_label' => 'boolean',
        ];
    }
}
