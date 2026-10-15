<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\StaffInvitation;
use App\Models\User;

/**
 * What an invitation's page asks of whoever opened it (CHW-146): the invited
 * address, signed in, joins, choosing a password first if it has neither one
 * nor a passkey (staff never sign in by email link, MaySignInByLink); another account can't use
 * it; a guest signs in to the address's account, or signs up with it.
 */
final readonly class DescribeStaffInvitation
{
    public const string JOIN = 'join';

    public const string CHOOSE_PASSWORD = 'choose-password';

    public const string OTHER_ACCOUNT = 'other-account';

    public const string SIGN_IN = 'sign-in';

    public const string SIGN_UP = 'sign-up';

    /** @return self::JOIN|self::CHOOSE_PASSWORD|self::OTHER_ACCOUNT|self::SIGN_IN|self::SIGN_UP */
    public function handle(StaffInvitation $invitation, ?User $user): string
    {
        if ($user instanceof User) {
            return match (true) {
                $user->email !== $invitation->email => self::OTHER_ACCOUNT,
                $user->hasPassword() || $user->passkeys()->exists() => self::JOIN,
                default => self::CHOOSE_PASSWORD,
            };
        }

        return User::query()->where('email', $invitation->email)->exists() ? self::SIGN_IN : self::SIGN_UP;
    }
}
