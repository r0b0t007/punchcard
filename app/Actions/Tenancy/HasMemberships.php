<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\User;

/**
 * Whether the user runs or works in a business, as ResolveTenant counts it
 * (CHW-27): a membership of an operating business (not suspended, neither
 * it nor its organization archived), or org admin of an operating
 * organization. They are sent home to the dashboard; everyone else to My
 * cards. A membership ResolveTenant ignores (a cancelled setup, an archived
 * business) never strands its user on a dashboard with no tenant: both read
 * FindMemberships.
 */
final readonly class HasMemberships
{
    public function __construct(private FindMemberships $findMemberships) {}

    public function handle(User $user): bool
    {
        return ! $this->findMemberships->handle($user)->isEmpty();
    }
}
