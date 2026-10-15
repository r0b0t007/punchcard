<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;

/**
 * Signs a customer in from their email link (CHW-23), for a year (the
 * remember cookie, config/auth.php): taps land in Safari, which must stay
 * signed in. Their address is confirmed (MaySignInByLink), and the link
 * proved the inbox, so the password counts as just confirmed (adding a
 * passkey needs no password).
 * Through the guard, so Login fires and a waiting tap follows the new
 * session (RekeyTapClaimToken).
 */
final readonly class SignInCustomer
{
    public function __construct(private StatefulGuard $guard) {}

    public function handle(Request $request, User $user): void
    {

        $this->guard->login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->passwordConfirmed();
    }
}
