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
use App\Support\Tenancy\SingleBusinessAccounts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

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
            $businesses = $this->businesses($user)->with('organization')->orderBy('name')->get();

            return new Memberships(
                BusinessMember::query()->where('user_id', $user->id)->get(['business_id', 'role', 'location_id'])->keyBy('business_id'),
                $businesses,
                $this->administered($user)->get(),
                SingleBusinessAccounts::among(array_values(array_unique($businesses->map(fn (Business $business): int => $business->organization_id)->all()))),
            );
        });
    }

    /** Whether the user runs or works anywhere, by the same rules, without loading it all: a customer doesn't (home, CHW-27). */
    public function any(User $user): bool
    {
        return $this->context->bypass(fn (): bool => $this->businesses($user)->exists() || $this->administered($user)->exists());
    }

    /** @return Builder<Business> the operating businesses the user is a member of */
    private function businesses(User $user): Builder
    {
        return Business::query()->whereIn('id', BusinessMember::query()->where('user_id', $user->id)->select('business_id'))->operating();
    }

    /** @return Builder<Organization> the operating organizations the user is org admin of */
    private function administered(User $user): Builder
    {
        return Organization::query()
            ->whereIn('id', OrganizationMember::query()->where('user_id', $user->id)->where('role', OrganizationRole::OrgAdmin)->select('organization_id'))
            ->operating();
    }
}
