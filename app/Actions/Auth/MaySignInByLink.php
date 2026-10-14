<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\PlatformRole;
use App\Models\BusinessMember;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Whether an account may sign in with an email link (CHW-23): customers
 * only. A link proves only the inbox, so an account with a second factor
 * (two-factor on), a role in a business or organization, or the platform
 * admin signs in with its password or passkey, never a link that would
 * skip it. Any membership counts, even of a business not operating yet.
 * Nor an account with a password whose address was never confirmed: it may
 * have been registered by someone else, or its owner may have mistyped a new
 * address, so a link to that address must not confirm it; its owner signs in
 * with the password (or chooses one again by email).
 */
final readonly class MaySignInByLink
{
    public function __construct(private TenantContext $context) {}

    public function handle(User $user): bool
    {
        return ! $user->isAnonymised()
            && ($user->hasVerifiedEmail() || $user->password === null)
            && ! $user->hasEnabledTwoFactorAuthentication()
            && ! $user->hasRole(PlatformRole::Admin->value)
            && ! $this->context->bypass(fn (): bool => BusinessMember::query()->where('user_id', $user->id)->exists()
                || OrganizationMember::query()->where('user_id', $user->id)->exists());
    }
}
