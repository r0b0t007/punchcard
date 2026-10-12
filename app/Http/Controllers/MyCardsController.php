<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Cards\ListMyCards;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My cards (CHW-27, C5, ListMyCards): GET /app, the customer's home, and
 * the cards of anyone who collects stamps, an owner or staff member too.
 * Customers land here after signing in (StartController). Never cached.
 */
final class MyCardsController extends Controller
{
    public function index(Request $request, ListMyCards $listMyCards): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        return Inertia::render('app/cards', $listMyCards->handle($user, CarbonImmutable::now()));
    }
}
