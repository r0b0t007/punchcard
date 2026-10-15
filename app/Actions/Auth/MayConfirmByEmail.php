<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Whether an account may confirm it's them with a link by email (CHW-23):
 * one without a password, that a link could sign in anyway
 * (MaySignInByLink: a confirmed address, no second factor, no role). For
 * anyone else the inbox would stand in for a password or a second factor,
 * so they use those, or a passkey.
 */
final readonly class MayConfirmByEmail
{
    public function __construct(private MaySignInByLink $maySignInByLink) {}

    public function handle(User $user): bool
    {
        return ! $user->hasPassword() && $this->maySignInByLink->handle($user);
    }
}
