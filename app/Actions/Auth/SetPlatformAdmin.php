<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\PlatformRole;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Makes a user a platform admin, or no longer one (CHW-22). Only an account
 * that could get into the panel becomes one: a real person (not anonymised)
 * with a verified email and two-factor authentication confirmed, which
 * Fortify then asks for at every password sign-in. Revoking never creates
 * the role.
 */
final readonly class SetPlatformAdmin
{
    /**
     * @throws NotEligibleForAdmin
     */
    public function handle(User $user, bool $admin): void
    {
        if (! $admin) {
            if (Role::query()->where('name', PlatformRole::Admin->value)->where('guard_name', 'web')->exists()) {
                $user->removeRole(PlatformRole::Admin->value);
            }

            return;
        }

        $refusal = match (true) {
            $user->isAnonymised() => 'This account was deleted.',
            ! $user->hasVerifiedEmail() => 'Verify the email first.',
            $user->two_factor_confirmed_at === null => 'Turn on two-factor authentication first.',
            default => null,
        };

        if ($refusal !== null) {
            throw new NotEligibleForAdmin($refusal);
        }

        $user->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
    }
}
