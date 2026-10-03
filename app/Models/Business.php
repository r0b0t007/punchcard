<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\OrganizationType;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use App\Support\Tenancy\TenantScope;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 * @property Carbon|null $archived_at
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
     * Only franchise HQ adds a business: an org admin working across the
     * organization (no business selected), in their own franchise. A franchisee
     * cannot add a sibling, and an independent café or chain has exactly one
     * business (OrganizationType); becoming a franchise is an admin action, in
     * bypass().
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $context = app(TenantContext::class);

        if (! isset($values['organization_id'])) {
            throw new LogicException('Creating a business needs a tenant, or TenantContext::bypass().');
        }

        ArchivedSites::assertOrganizationOpen($values['organization_id'], 'A business', lock: true);

        if ($context->isBypassed()) {
            return;
        }

        if (! $context->isOrgAdmin() || $context->businessId() !== null) {
            throw new LogicException('Only an org admin, working across the organization, can create a business.');
        }

        $status = $values['status'] ?? BusinessStatus::Pending->value;

        if (($status instanceof BusinessStatus ? $status : BusinessStatus::tryFrom((string) $status)) !== BusinessStatus::Pending || ($values['plan'] ?? null) !== null || ($values['archived_at'] ?? null) !== null) {
            throw new LogicException('A new business starts pending, open and without a plan; verification, billing and admin actions change them, in TenantContext::bypass().');
        }

        if ($context->organizationId() === null || (int) $values['organization_id'] !== $context->organizationId()) {
            throw new LogicException('Cannot create a business in another organization.');
        }

        if (! $this->isFranchise($context->organizationId())) {
            throw new LogicException('Only a franchise has more than one business.');
        }
    }

    /**
     * The owner (or an org admin) may update the business; staff may not. Only
     * franchise HQ (org admin, across the organization) removes a franchisee;
     * closing an independent café or chain is an admin action, in bypass().
     * Status, plan and archiving change only through verification, billing
     * and archive actions.
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

        if ($operation === 'delete' && (! $context->isOrgAdmin() || $context->businessId() !== null || ! $this->isFranchise($context->organizationId()))) {
            throw new LogicException('Only franchise HQ, working across the organization, can delete a business.');
        }

        if ($operation === 'update' && ! $context->isOrgAdmin() && $context->businessRole() !== BusinessRole::Owner) {
            throw new LogicException('Only the owner or an org admin can change the business.');
        }

        if (array_intersect(array_keys($values), ['status', 'plan', 'archived_at']) !== []) {
            throw new LogicException('Business status, plan and archiving change through verification, billing and archive actions, in TenantContext::bypass().');
        }
    }

    /**
     * Businesses someone may work in, or run jobs for: not suspended, and
     * neither it nor its organization archived. A pending one counts, so its
     * owner can set up.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function operating(Builder $query): void
    {
        $query->where('status', '!=', BusinessStatus::Suspended)->unarchived();
    }

    /**
     * Businesses not closed: neither it nor its organization archived
     * (ArchivedSites is the same rule for writes).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unarchived(Builder $query): void
    {
        $query->whereNull('archived_at')
            ->whereHas('organization', fn (Builder $organization) => $organization->whereNull('archived_at'));
    }

    /** Reads the stored organization type, in bypass(): bulk writes have no loaded model to ask. */
    private function isFranchise(?int $organizationId): bool
    {
        return app(TenantContext::class)->bypass(fn (): bool => Organization::query()
            ->whereKey($organizationId)
            ->where('type', OrganizationType::Franchise)
            ->exists());
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Owners and staff (business_user). Uses the guarded BusinessMember pivot, so
     * attach(), detach(), sync(), toggle() and updateExistingPivot() all go through
     * model saves (MembershipTest pins this). Never use newPivotQuery() or
     * newPivotStatement(): they skip the guards.
     *
     * @return BelongsToMany<User, $this, BusinessMember>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(BusinessMember::class)
            ->withPivot('id', 'role', 'location_id', 'organization_id')
            ->withTimestamps();
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
            'archived_at' => 'datetime',
        ];
    }
}
