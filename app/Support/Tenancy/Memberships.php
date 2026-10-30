<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Organization;
use Illuminate\Support\Collection;

/**
 * A user's place in businesses (ADR 0006), as FindMemberships loads it:
 * their business_user rows, the operating businesses those name (with their
 * organization), and the operating organizations they are org admin of.
 * Every tenancy rule reads it (ResolveTenant, DescribeTenants,
 * HasMemberships), so where the app puts a user, what the switcher offers
 * and where they are sent home never disagree.
 */
final readonly class Memberships
{
    /**
     * @param  Collection<int, BusinessMember>  $members  by business id
     * @param  Collection<int, Business>  $businesses  operating, by name
     * @param  Collection<int, Organization>  $administered  operating, org admin of
     */
    public function __construct(
        public Collection $members,
        public Collection $businesses,
        public Collection $administered,
    ) {}

    /** Whether the user runs or works anywhere: a customer has no memberships. */
    public function isEmpty(): bool
    {
        return $this->businesses->isEmpty() && $this->administered->isEmpty();
    }

    /**
     * The business that is an organization's account, when the user works
     * in it: an independent café or a one-company chain
     * (SingleBusinessAccounts). There the business is the account, never a
     * network of one.
     */
    public function businessThatIs(Organization $organization): ?Business
    {
        $business = $this->businesses->firstWhere('organization_id', $organization->id);

        return $business instanceof Business && SingleBusinessAccounts::isTheAccount($organization->id) ? $business : null;
    }

    /**
     * The networks the user runs, as the switcher offers them: every
     * organization they are org admin of, but those whose business is the
     * account and works it (businessThatIs).
     *
     * @return Collection<int, Organization>
     */
    public function networks(): Collection
    {
        return $this->administered->reject(fn (Organization $organization): bool => $this->businessThatIs($organization) instanceof Business)->values();
    }
}
