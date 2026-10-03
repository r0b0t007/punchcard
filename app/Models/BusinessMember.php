<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BusinessRole;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A business_user row: an owner or staff member of one business, staff
 * optionally limited to one location. Site data, so it is scoped and guarded
 * like a location, and only an owner or org admin writes it; Business::members()
 * uses this pivot so every attach, detach, sync and update goes through that.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property int $user_id
 * @property int|null $location_id
 * @property BusinessRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('business_user', incrementing: true)]
#[UseEloquentBuilder(TenantBuilder::class)]
class BusinessMember extends Pivot implements TenantModel
{
    use BelongsToBusiness {
        assertTenantInsert as assertSiteDataInsert;
    }
    use GuardsTenantWrites;

    /**
     * Only an owner or org admin adds staff or owners (staff cannot add anyone),
     * and nobody joins an archived business or location, also in bypass().
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertSiteDataInsert($values);
        ArchivedSites::assertOpen($values['business_id'] ?? null, $values['location_id'] ?? null, 'A membership', lock: true);
        $this->assertCanManageMembers();
    }

    /**
     * Only an owner or org admin changes or removes memberships, so staff cannot
     * promote themselves or lift their own location limit. A membership never
     * moves to another user.
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        if (array_key_exists('user_id', $values)) {
            throw new LogicException('A membership cannot move to another user.');
        }

        $this->assertCanManageMembers();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => BusinessRole::class,
        ];
    }

    private function assertCanManageMembers(): void
    {
        $context = app(TenantContext::class);

        if (! $context->isBypassed() && ! $context->canManageMembers()) {
            throw new LogicException('Only an owner or org admin manages the staff of a business.');
        }
    }
}
