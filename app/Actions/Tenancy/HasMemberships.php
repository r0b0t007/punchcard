<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\BusinessMember;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Whether the user runs or works in a business: any business_user or
 * organization_user row, whatever its state (CHW-27). They land on the
 * dashboard; everyone else is a customer and lands on My cards. One rule
 * for both ways, so neither page sends the user back to the other.
 */
final readonly class HasMemberships
{
    public function __construct(private TenantContext $context) {}

    public function handle(User $user): bool
    {
        return $this->context->bypass(fn (): bool => BusinessMember::query()->where('user_id', $user->id)->exists()
            || OrganizationMember::query()->where('user_id', $user->id)->exists());
    }
}
