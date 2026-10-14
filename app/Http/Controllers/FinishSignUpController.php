<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\CreateCustomer;
use App\Actions\Auth\SignInCustomer;
use App\Http\Requests\FinishSignUpRequest;
use App\Models\User;
use App\Support\Auth\ProvenEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A new customer's first details (CHW-23), after an email link proved
 * their address (LoginLinkController::PROVEN_EMAIL, for 15 minutes): their
 * name, 16 or older, news by email or not. Then the account (CreateCustomer),
 * signed in for a year, and on where they were going (a waiting tap too).
 * Without a proven address, back to asking for a link.
 */
final class FinishSignUpController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $email = ProvenEmail::of($request->session());

        return $email === null
            ? to_route('login-link.create')
            : Inertia::render('auth/finish-sign-up', ['email' => $email]);
    }

    public function store(FinishSignUpRequest $request, CreateCustomer $createCustomer, SignInCustomer $signInCustomer): RedirectResponse
    {
        $email = ProvenEmail::of($request->session());

        if ($email === null) {
            return to_route('login-link.create');
        }

        $customer = $createCustomer->handle($email, (string) $request->validated('name'), $request->wantsNews());
        ProvenEmail::forget($request->session());

        // The address now belongs to an account that signs in otherwise (MaySignInByLink): say so.
        if (! $customer instanceof User) {
            return to_route('login-link.create')->withErrors(['email' => __('This address signs in with its password or a passkey.')]);
        }
        $signInCustomer->handle($request, $customer);

        return redirect()->intended(route('start'));
    }
}
