<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Onboarding\SignUpBusinessOwner;
use App\Http\Requests\BusinessSignUpRequest;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business sign-up (CHW-31, B1 for the pilot: no plan picker).
 *
 * - GET /business/register: the form, for guests.
 * - POST /business/register: the account and its business
 *   (SignUpBusinessOwner), signed in as Fortify's registration does, then
 *   to the dashboard, which sends a business still to set up into the
 *   onboarding wizard. Throttled per client address (business-signup): each
 *   sign-up creates an organization. A customer registers through Fortify,
 *   unthrottled, and a tap waiting to be claimed (url.intended) is left for
 *   them.
 */
final class BusinessRegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/register-business', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(BusinessSignUpRequest $request, SignUpBusinessOwner $signUp): RedirectResponse
    {
        $owner = $signUp->handle($request->account(), $request->string('business_name')->toString());

        event(new Registered($owner));
        Auth::login($owner);
        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
