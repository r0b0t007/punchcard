<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\BusinessRole;
use App\Enums\OrganizationRole;
use App\Models\BusinessMember;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Whether the user owns a business or administers an organization, which
 * they must hand over or close before deleting their account (CHW-139), so
 * no business is left without someone to run it. Reads across tenants.
 */
final readonly class MustHandOverBusiness
{
    public function __construct(private TenantContext $context) {}

    public function handle(User $user): bool
    {
        return $this->context->bypass(fn (): bool => BusinessMember::query()->where('user_id', $user->id)->where('role', BusinessRole::Owner)->exists()
            || OrganizationMember::query()->where('user_id', $user->id)->where('role', OrganizationRole::OrgAdmin)->exists());
    }
}
