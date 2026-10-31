<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Enums\OrganizationType;
use App\Models\Business;

/**
 * An independent café or a one-company chain: an organization that is not a
 * franchise and has exactly one business, archived ones counted (ADR 0006,
 * CHW-22). There the business is the account, so its owner runs the
 * organization too. Counting archived businesses fails closed: a co-owner of
 * the remaining one never becomes org admin over another's customers.
 */
final class SingleBusinessAccounts
{
    public static function isTheAccount(mixed $organizationId): bool
    {
        return is_numeric($organizationId) && self::among([(int) $organizationId]) !== [];
    }

    /**
     * Those of the organizations that are a single business's account, in one query (CHW-315, Memberships).
     *
     * @param  list<int>  $organizationIds
     * @return list<int>
     */
    public static function among(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        /** @var list<int> */
        return app(TenantContext::class)->bypass(fn (): array => Business::query()
            ->whereIn('organization_id', $organizationIds)
            ->whereHas('organization', fn ($organization) => $organization->where('type', '!=', OrganizationType::Franchise))
            ->groupBy('organization_id')
            ->havingRaw('count(*) = 1')
            ->pluck('organization_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all());
    }
}
