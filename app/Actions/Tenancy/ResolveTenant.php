<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Sets the TenantContext for a signed-in user (ADR 0006).
 *
 * A choice from the tenant switcher ("org:5" or "business:7") wins when the
 * user belongs to it. Otherwise, when every membership is in one organization:
 * - exactly one business there: that business (an owner or staff member, an
 *   independent café's owner, or HQ that also runs one site);
 * - org admin of it: the organization (every business in it);
 * - anything else: no tenant (fail closed) until the user picks.
 * Memberships spread over several organizations always need a choice.
 * Customers have no memberships and get no tenant. A suspended business does
 * not resolve at all (fail closed); a pending one does, so its owner can set up.
 *
 * The context also carries what the user may do there: org admin rights from
 * organization_user, the business role from business_user.
 */
final readonly class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(User $user, ?string $choice = null): void
    {
        $this->context->clear();

        $this->context->bypass(function () use ($user, $choice): void {
            $roles = BusinessMember::query()->where('user_id', $user->id)->pluck('role', 'business_id');
            $businesses = Business::query()
                ->whereIn('id', $roles->keys())
                ->where('status', '!=', BusinessStatus::Suspended)
                ->with('organization')
                ->get();
            $organizations = Organization::query()
                ->whereIn('id', OrganizationMember::query()->where('user_id', $user->id)->select('organization_id'))
                ->get();

            [$type, $id] = $this->parseChoice($choice);

            $enter = function (Business $business) use ($roles, $organizations): void {
                $role = $roles->get($business->id);

                $this->context->set(
                    $business->organization,
                    $business,
                    orgAdmin: $organizations->contains('id', $business->organization_id),
                    businessRole: $role instanceof BusinessRole ? $role : BusinessRole::tryFrom((string) $role),
                );
            };

            if ($type === 'business' && ($business = $businesses->firstWhere('id', $id)) instanceof Business) {
                $enter($business);

                return;
            }

            if ($type === 'org' && ($organization = $organizations->firstWhere('id', $id)) instanceof Organization) {
                $this->context->set($organization, orgAdmin: true);

                return;
            }

            $organizationIds = $businesses->pluck('organization_id')->merge($organizations->pluck('id'))->unique();

            if ($organizationIds->count() !== 1) {
                return;
            }

            if ($businesses->count() === 1) {
                $enter($businesses->first());

                return;
            }

            if ($organizations->count() === 1) {
                $this->context->set($organizations->first(), orgAdmin: true);
            }
        });
    }

    /**
     * @return array{0: 'org'|'business'|null, 1: int}
     */
    private function parseChoice(?string $choice): array
    {
        if ($choice === null || preg_match('/^(org|business):([1-9]\d*)$/', $choice, $matches) !== 1) {
            return [null, 0];
        }

        return [$matches[1] === 'org' ? 'org' : 'business', (int) $matches[2]];
    }
}
