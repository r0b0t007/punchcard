<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Contracts\Session\Session;

/**
 * Whether the session confirmed it's them lately, as RequirePassword counts
 * it (auth.password_timeout): by password, passkey or a confirm link
 * (CHW-23). What stands in for the current password of an account that
 * has none.
 */
final class RecentConfirmation
{
    public static function holds(Session $session): bool
    {
        // As RequirePassword counts it: confirmed while no longer ago than the timeout.
        return now()->getTimestamp() - (int) $session->get('auth.password_confirmed_at', 0) <= (int) config('auth.password_timeout');
    }
}
