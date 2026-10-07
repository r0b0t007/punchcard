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
        return app(TenantContext::class)->bypass(fn (): bool => Business::query()
            ->where('organization_id', $organizationId)
            ->whereHas('organization', fn ($organization) => $organization->where('type', '!=', OrganizationType::Franchise))
            ->count() === 1);
    }
}
