<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signs a customer in from their email link (CHW-23), for a year (the
 * remember cookie, config/auth.php): taps land in Safari, which must stay
 * signed in. The link proved the inbox, so the email is verified and the
 * password counts as just confirmed (adding a passkey needs no password).
 * Through the guard, so Login fires and a waiting tap follows the new
 * session (RekeyTapClaimToken).
 *
 * An account whose email was never confirmed may have been registered by
 * someone else, with their password: the link proves the address is this
 * person's, so whatever that someone set up goes (password, remember
 * token, sessions, passkeys) before the account is confirmed and theirs.
 */
final readonly class SignInCustomer
{
    public function __construct(private StatefulGuard $guard) {}

    public function handle(Request $request, User $user): void
    {
        if (! $user->hasVerifiedEmail()) {
            $this->takeBack($user);

            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
            }
        }

        $this->guard->login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->passwordConfirmed();
    }

    private function takeBack(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->forceFill(['password' => null, 'remember_token' => Str::random(60)])->save();
            $user->passkeys()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
    }
}
