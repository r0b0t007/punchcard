<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The business sign-up page (CHW-31, B1 for the pilot: no plan picker). It
 * posts to Fortify's registration with a business name, and CreateNewUser
 * creates the account with its business. A page left behind (a tap or a
 * join link) no longer decides where the owner lands: the wizard does.
 */
final class BusinessRegistrationController extends Controller
{
    public function create(Request $request): Response
    {
        $request->session()->forget('url.intended');

        return Inertia::render('auth/register-business', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }
}
