<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Cards\DescribeMyCard;
use App\Actions\Cards\ListMyCards;
use App\Actions\Cards\SetFollow;
use App\Actions\Push\DescribePush;
use App\Actions\Rewards\DescribeRedeemNotices;
use App\Actions\Rewards\DismissRedeemNotice;
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
    public function index(Request $request, ListMyCards $listMyCards, DescribeRedeemNotices $describeRedeemNotices): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        return Inertia::render('app/cards', [
            ...$listMyCards->handle($user, CarbonImmutable::now()),
            // Rewards staff redeemed with a scan that nothing told the customer about yet (CHW-228, 14b).
            'redeemNotices' => $describeRedeemNotices->handle($user),
        ]);
    }

    /** Got it on a notice of a reward staff redeemed with a scan (CHW-228): only the customer's own. */
    public function dismissRedeemNotice(Request $request, int $reward, DismissRedeemNotice $dismissRedeemNotice): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        if (! $dismissRedeemNotice->handle($user, $reward)) {
            abort(404);
        }

        return to_route('app.cards');
    }

    public function show(MyCardRequest $request, DescribeMyCard $describeMyCard, DescribePush $describePush): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        return Inertia::render('app/card', [
            ...$describeMyCard->handle($request->enrollment(), $user, CarbonImmutable::now()),
            // Following asks for notifications under the box (CHW-41, 11a).
            'push' => $describePush->handle($user),
        ]);
    }

    public function follow(FollowRequest $request, SetFollow $setFollow): RedirectResponse
    {
        $enrollment = $request->enrollment();
        $setFollow->handle($enrollment, $request->boolean('follow'));

        return to_route('app.cards.show', $enrollment->id);
    }
}
