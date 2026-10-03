<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\BusinessRole;
use App\Enums\OrganizationRole;
use App\Models\BusinessMember;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether the user owns a business or administers an organization, which
 * they must hand over or close before deleting their account (CHW-139), so
 * no business is left without someone to run it. A closed one (archived, or
 * in an archived organization) needs nobody. Reads across tenants.
 */
final readonly class MustHandOverBusiness
{
    public function __construct(private TenantContext $context) {}

    public function handle(User $user): bool
    {
        return $this->context->bypass(fn (): bool => BusinessMember::query()
            ->where('user_id', $user->id)
            ->where('role', BusinessRole::Owner)
            ->whereHas('business', fn (Builder $business) => $business->unarchived())
            ->exists()
            || OrganizationMember::query()
                ->where('user_id', $user->id)
                ->where('role', OrganizationRole::OrgAdmin)
                ->whereIn('organization_id', Organization::query()->whereNull('archived_at')->select('id'))
                ->exists());
    }
}
