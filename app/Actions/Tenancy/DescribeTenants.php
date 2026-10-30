<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\OrganizationRole;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\Tenancy\SingleBusinessAccounts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * What the tenant switcher offers (CHW-147, design 10a and 10d): every
 * business the user works in and every network they run, by organization,
 * in ResolveTenant's own terms (only operating ones), and which one the
 * app works in now. It is also all SelectTenant takes (choices()), so the
 * menu and the switch never disagree.
 *
 * A network is left out where the organization is a single business's
 * account (SingleBusinessAccounts) and the user works in that business:
 * there the business is the account, and its owner sees just the business.
 * Null for someone who works nowhere (a customer).
 *
 * @phpstan-type Entry array{choice: string, kind: 'business'|'network', name: string, role: string, logoUrl: string|null, initial: string, current: bool}
 */
final readonly class DescribeTenants
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  bool  $onlyCurrent  only the one worked in: an admin signed in as an owner is held to it (CHW-144)
     * @return array{current: Entry|null, groups: list<array{organizationId: int, organization: string, entries: list<Entry>}>}|null
     */
    public function handle(User $user, bool $onlyCurrent = false): ?array
    {
        $current = match (true) {
            $this->context->businessId() !== null => SetTenant::businessChoice($this->context->businessId()),
            $this->context->organizationId() !== null => SetTenant::organizationChoice($this->context->organizationId()),
            default => null,
        };

        [$memberships, $businesses, $networks] = $this->find($user);

        if ($businesses->isEmpty() && $networks->isEmpty()) {
            return null;
        }

        $organizations = $businesses->map(fn (Business $business): Organization => $business->organization)
            ->merge($networks)->unique('id')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        // Plain arrays, not a Collection: its generics would widen an entry's kind to a string.
        $groups = array_values(array_filter(array_map(fn (Organization $organization): array => [
            'organizationId' => $organization->id,
            'organization' => $organization->name,
            'entries' => array_values(array_filter([
                ...array_map(fn (Organization $network): array => $this->entry(
                    SetTenant::organizationChoice($network->id), true, __('Whole network'), __('Runs the network'), $network, $current,
                ), $networks->where('id', $organization->id)->values()->all()),
                ...array_map(fn (Business $business): array => $this->entry(
                    SetTenant::businessChoice($business->id), false, $business->name, (string) $memberships->get($business->id)?->role?->getLabel(), $organization, $current,
                ), $businesses->where('organization_id', $organization->id)->values()->all()),
            ], fn (array $candidate): bool => ! $onlyCurrent || $candidate['current'])),
        ], $organizations->all()), fn (array $group): bool => $group['entries'] !== []));

        $entries = array_merge(...array_column($groups, 'entries'));

        return [
            'current' => array_values(array_filter($entries, fn (array $entry): bool => $entry['current']))[0] ?? null,
            'groups' => $groups,
        ];
    }

    /**
     * Every choice the switcher offers the user: all SelectTenant takes.
     *
     * @return list<string>
     */
    public function choices(User $user): array
    {
        [, $businesses, $networks] = $this->find($user);

        return [
            ...$networks->map(fn (Organization $network): string => SetTenant::organizationChoice($network->id))->values()->all(),
            ...$businesses->map(fn (Business $business): string => SetTenant::businessChoice($business->id))->values()->all(),
        ];
    }

    /**
     * One business or network as the switcher shows it, ticked when it is the one worked in.
     *
     * @return Entry
     */
    private function entry(string $choice, bool $network, string $name, string $role, Organization $organization, ?string $current): array
    {
        return [
            'choice' => $choice,
            'kind' => $network ? 'network' : 'business',
            'name' => $name,
            'role' => $role,
            'logoUrl' => $organization->logoUrl(),
            'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
            'current' => $choice === $current,
        ];
    }

    /**
     * The user's memberships, the operating businesses they work in, and the operating networks they run.
     *
     * @return array{0: Collection<int, BusinessMember>, 1: Collection<int, Business>, 2: Collection<int, Organization>}
     */
    private function find(User $user): array
    {
        /** @var array{0: Collection<int, BusinessMember>, 1: Collection<int, Business>, 2: Collection<int, Organization>} */
        return $this->context->bypass(function () use ($user): array {
            $memberships = BusinessMember::query()->where('user_id', $user->id)->get(['business_id', 'role'])->keyBy('business_id');
            $businesses = Business::query()->whereIn('id', $memberships->keys())->operating()->with('organization')->orderBy('name')->get();
            $networks = Organization::query()
                ->whereIn('id', OrganizationMember::query()->where('user_id', $user->id)->where('role', OrganizationRole::OrgAdmin)->select('organization_id'))
                ->operating()
                ->get()
                ->reject(fn (Organization $organization): bool => $businesses->contains('organization_id', $organization->id)
                    && SingleBusinessAccounts::isTheAccount($organization->id));

            return [$memberships, $businesses, $networks];
        });
    }
}
