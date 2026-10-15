<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\DB;

/**
 * Sets the signed-in user's password, from settings. This browser stays
 * signed in, its remember cookie made again for the new password (it
 * carries a mark of the old one), and other devices are signed out
 * (logoutOtherDevices). An account choosing its first password (signed up
 * by email link, CHW-23) has had no password mark for other sessions to be
 * checked against, so they are ended here (database sessions).
 */
final readonly class SetPassword
{
    public function __construct(private StatefulGuard $guard) {}

    public function handle(User $user, string $password, string $sessionId): void
    {
        $first = $user->password === null;

        $user->update(['password' => $password]);
        $this->guard->logoutOtherDevices($password);

        if ($first) {
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $sessionId)->delete();
        }
    }
}
