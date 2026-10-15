<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\Validator;

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

    /**
     * A form's "confirm it's you first": where needed, an error on the field
     * unless the session confirmed lately. For a request's after().
     *
     * @return Closure(Validator): void
     */
    public static function rule(Session $session, string $field, bool $needed): Closure
    {
        return function (Validator $validator) use ($session, $field, $needed): void {
            if ($needed && ! self::holds($session)) {
                $validator->errors()->add($field, __('Confirm it\'s you first.'));
            }
        };
    }
}
