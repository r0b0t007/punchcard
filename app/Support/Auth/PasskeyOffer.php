<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * The passkey offered after an email-link sign-in (CHW-23): next time, Face
 * ID or a fingerprint. Started by SignInCustomer, shown while the account
 * has none and the session counts as confirmed (adding one needs it,
 * RecentConfirmation), until "not now". Never after a password sign-in.
 */
final class PasskeyOffer
{
    private const string KEY = 'passkey-offer';

    public static function start(Session $session): void
    {
        $session->put(self::KEY, true);
    }

    public static function holds(Session $session, User $user): bool
    {
        return $session->get(self::KEY) === true
            && RecentConfirmation::holds($session)
            && ! $user->passkeys()->exists();
    }

    public static function dismiss(Session $session): void
    {
        $session->forget(self::KEY);
    }
}
