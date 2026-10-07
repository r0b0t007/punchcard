<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\BusinessRole;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
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
 * Customers have no memberships and get no tenant. A suspended or archived
 * business does not resolve at all (fail closed), nor does any business of an
 * archived organization; a pending one does, so its owner can set up.
 * Neither does an archived organization, or an independent or chain one whose
 * business is suspended or archived: there the business is the account.
 * Franchise HQ keeps working while a franchisee is suspended or archived.
 *
 * The context also carries what the user may do there: org admin rights from
 * an org_admin row in organization_user, the business role and any one
 * location a staff member is limited to from business_user (the role cast
 * makes an unknown value fail closed). The owner of the only business of an
 * independent café or a chain is its org admin too (CHW-22): there the
 * business is the account, so they run the card program and the brand.
 */
final readonly class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(User $user, ?string $choice = null): void
    {
        $this->context->clear();

        $this->context->bypass(function () use ($user, $choice): void {
            $memberships = BusinessMember::query()->where('user_id', $user->id)->get(['business_id', 'role', 'location_id'])->keyBy('business_id');
            $businesses = Business::query()
                ->whereIn('id', $memberships->keys())
                ->operating()
                ->with('organization')
                ->get();
            $organizations = Organization::query()
                ->whereIn('id', OrganizationMember::query()->where('user_id', $user->id)->where('role', OrganizationRole::OrgAdmin)->select('organization_id'))
                ->operating()
                ->get();

            [$type, $id] = $this->parseChoice($choice);

            $enter = function (Business $business) use ($memberships, $organizations): void {
                $membership = $memberships->get($business->id);
                $role = $membership?->role;

                $this->context->set(
                    $business->organization,
                    $business,
                    orgAdmin: $organizations->contains('id', $business->organization_id),
                    businessRole: $role instanceof BusinessRole ? $role : null,
                    locationId: $role === BusinessRole::Staff ? $membership->location_id : null,
                    ownsTheAccount: $role === BusinessRole::Owner && $this->isTheAccount($business),
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

    /** The business is its organization's only one, and the organization not a franchise (in bypass). */
    private function isTheAccount(Business $business): bool
    {
        return $business->organization->type !== OrganizationType::Franchise
            && Business::query()->where('organization_id', $business->organization_id)->count() === 1;
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
