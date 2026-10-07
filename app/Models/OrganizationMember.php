<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An organization_user row: an org admin (franchise HQ, or the owner of an
 * independent café). Scoped to the current organization; only an org admin
 * of it (TenantContext::isOrgAdmin()) or bypass() adds, changes or removes
 * org admins.
 * Organization::admins() uses this pivot so its writes are guarded.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property OrganizationRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('organization_user', incrementing: true)]
#[UseEloquentBuilder(TenantBuilder::class)]
class OrganizationMember extends Pivot implements TenantModel
{
    use GuardsTenantWrites;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function fillTenantColumns(): void
    {
        $this->organization_id ??= app(TenantContext::class)->organizationId();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        if (! isset($values['organization_id'])) {
            throw new LogicException('An org admin membership needs an organization.');
        }

        ArchivedSites::assertOrganizationOpen($values['organization_id'], 'A membership', lock: true);
        $this->assertOrgAdminContext((int) $values['organization_id']);
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        if (array_key_exists('user_id', $values)) {
            throw new LogicException('A membership cannot move to another user.');
        }

        $this->assertOrgAdminContext(app(TenantContext::class)->organizationId());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
        ];
    }

    private function assertOrgAdminContext(?int $organizationId): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        // A real org_admin row, not owning an independent café's account (TenantContext::managesOrgAdmins).
        if (! $context->managesOrgAdmins() || $context->organizationId() === null || $organizationId !== $context->organizationId()) {
            throw new LogicException('Only an org admin of the organization manages its org admins.');
        }
    }
}
