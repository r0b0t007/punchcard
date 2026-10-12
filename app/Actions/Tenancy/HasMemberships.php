<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\OrganizationRole;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Whether the user runs or works in a business, as ResolveTenant counts it
 * (CHW-27): a membership of an operating business (not suspended, neither
 * it nor its organization archived), or org admin of an operating
 * organization. They are sent home to the dashboard; everyone else to My
 * cards. A membership ResolveTenant ignores (a cancelled setup, an archived
 * business) never strands its user on a dashboard with no tenant.
 */
final readonly class HasMemberships
{
    public function __construct(private TenantContext $context) {}

    public function handle(User $user): bool
    {
        return $this->context->bypass(fn (): bool => BusinessMember::query()
            ->where('user_id', $user->id)
            ->whereIn('business_id', Business::query()->operating()->select('id'))
            ->exists()
            || OrganizationMember::query()
                ->where('user_id', $user->id)
                ->where('role', OrganizationRole::OrgAdmin)
                ->whereIn('organization_id', Organization::query()->operating()->select('id'))
                ->exists());
    }
}
