<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Cards\ListMyCards;
use App\Actions\Tenancy\HasMemberships;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's home (CHW-27): GET /app, My cards (C5, ListMyCards), where
 * customers land after signing in. Whoever runs or works in a business goes
 * to their dashboard instead (HasMemberships). Never cached.
 */
final class MyCardsController extends Controller
{
    public function index(Request $request, HasMemberships $hasMemberships, ListMyCards $listMyCards): Response|RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        if ($hasMemberships->handle($user)) {
            return to_route('dashboard');
        }

        return Inertia::render('app/cards', $listMyCards->handle($user, CarbonImmutable::now()));
    }
}
