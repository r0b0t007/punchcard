<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\OrganizationRole;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\Tenancy\Memberships;
use App\Support\Tenancy\TenantContext;

/**
 * A user's memberships, in the one place tenancy reads them (CHW-315): only
 * what operates counts (a suspended or archived business, or one of an
 * archived organization, never resolves; a pending one does, so its owner
 * can set up), outside any tenant (the user's own rows, across every
 * organization).
 */
final readonly class FindMemberships
{
    public function __construct(private TenantContext $context) {}

    public function handle(User $user): Memberships
    {
        return $this->context->bypass(function () use ($user): Memberships {
            $members = BusinessMember::query()->where('user_id', $user->id)->get(['business_id', 'role', 'location_id'])->keyBy('business_id');

            return new Memberships(
                $members,
                Business::query()->whereIn('id', $members->keys())->operating()->with('organization')->orderBy('name')->get(),
                Organization::query()
                    ->whereIn('id', OrganizationMember::query()->where('user_id', $user->id)->where('role', OrganizationRole::OrgAdmin)->select('organization_id'))
                    ->operating()
                    ->get(),
            );
        });
    }
}
