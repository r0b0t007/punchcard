<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\HasMemberships;
use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where a signed-in user goes home (CHW-27): after signing in, registering
 * or verifying their email (Fortify's home), or opening a guest page.
 * Whoever runs or works in a business goes to the dashboard
 * (HasMemberships), the platform admin to the admin panel, everyone else
 * to My cards. The query string follows (?verified=1).
 */
final class StartController extends Controller
{
    public function __invoke(Request $request, HasMemberships $hasMemberships): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $home = match (true) {
            $hasMemberships->handle($user) => route('dashboard'),
            $user->hasRole(PlatformRole::Admin->value) => url('/admin'),
            default => route('app.cards'),
        };

        return redirect()->to($request->getQueryString() === null ? $home : $home.'?'.$request->getQueryString());
    }
}
