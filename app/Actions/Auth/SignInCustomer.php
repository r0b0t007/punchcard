<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;

/**
 * Signs a customer in from their email link (CHW-23), for a year (the
 * remember cookie, config/auth.php): taps land in Safari, which must stay
 * signed in. The link proved the inbox, so the email is verified and the
 * password counts as just confirmed (adding a passkey needs no password).
 * Through the guard, so Login fires and a waiting tap follows the new
 * session (RekeyTapClaimToken).
 *
 * An account whose address isn't confirmed yet here has no password
 * (MaySignInByLink): a customer who changed it in settings, whom the link
 * confirms.
 */
final readonly class SignInCustomer
{
    public function __construct(private StatefulGuard $guard) {}

    public function handle(Request $request, User $user): void
    {
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        $this->guard->login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->passwordConfirmed();
    }
}
