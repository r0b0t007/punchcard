<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\LoginLink;
use Illuminate\Contracts\Session\Session;

/**
 * The address an email link just proved, for a new customer's first details
 * (CHW-23): kept in the browser's session (a new session id first, so one
 * planted beforehand never carries it) for as long as a link lasts.
 */
final class ProvenEmail
{
    private const string KEY = 'sign-up.proven-email';

    public static function remember(Session $session, string $email): void
    {
        $session->regenerate();
        $session->put(self::KEY, ['email' => $email, 'until' => now()->addMinutes(LoginLink::MINUTES)->getTimestamp()]);
    }

    /** The proven address, while it is still good; else null. */
    public static function of(Session $session): ?string
    {
        $proven = $session->get(self::KEY);

        return is_array($proven) && is_string($proven['email'] ?? null) && (int) ($proven['until'] ?? 0) > now()->getTimestamp()
            ? $proven['email']
            : null;
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::KEY);
    }
}
