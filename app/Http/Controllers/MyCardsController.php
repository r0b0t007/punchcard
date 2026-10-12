<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Cards\DescribeMyCard;
use App\Actions\Cards\ListMyCards;
use App\Actions\Cards\SetFollow;
use App\Http\Requests\FollowRequest;
use App\Http\Requests\MyCardRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's cards (CHW-27). Never cached.
 *
 * - GET /app: My cards (C5, ListMyCards), the customer's home, and the
 *   cards of anyone who collects stamps, an owner or staff member too.
 *   Customers land here after signing in (StartController).
 * - GET /app/cards/{enrollment}: one card in full (C6, DescribeMyCard).
 * - PATCH /app/cards/{enrollment}/follow: "Follow for offers" (SetFollow).
 *
 * {enrollment} is one of the customer's own cards (MyCardRequest): anyone
 * else's is a 404.
 */
final class MyCardsController extends Controller
{
    public function index(Request $request, ListMyCards $listMyCards): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        return Inertia::render('app/cards', $listMyCards->handle($user, CarbonImmutable::now()));
    }

    public function show(MyCardRequest $request, DescribeMyCard $describeMyCard): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        return Inertia::render('app/card', $describeMyCard->handle($request->enrollment(), $user, CarbonImmutable::now()));
    }

    public function follow(FollowRequest $request, SetFollow $setFollow): RedirectResponse
    {
        $enrollment = $request->enrollment();
        $setFollow->handle($enrollment, $request->boolean('follow'));

        return to_route('app.cards.show', $enrollment->id);
    }
}
