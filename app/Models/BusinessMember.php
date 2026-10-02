<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BusinessRole;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A business_user row: an owner or staff member of one business, staff
 * optionally limited to one location. Site data, so it is scoped and guarded
 * like a location; Business::members() uses this pivot so attach(),
 * detach($ids), sync() and updateExistingPivot() go through those guards.
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
    use BelongsToBusiness;
    use GuardsTenantWrites;

    /**
     * A membership never moves to another user. Who may change roles, and the
     * staff location limit, are policy questions (CHW-22).
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        if (array_key_exists('user_id', $values)) {
            throw new LogicException('A membership cannot move to another user.');
        }
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
}
