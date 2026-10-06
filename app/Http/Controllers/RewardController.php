<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Rewards\CloseRedeemWindow;
use App\Actions\Rewards\DescribeCustomerReward;
use App\Actions\Rewards\ListCustomerRewards;
use App\Actions\Rewards\OpenRedeemWindow;
use App\Actions\Rewards\RedeemRefused;
use App\Http\Requests\CustomerRewardRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's rewards (CHW-26). Every response is no-store (NeverCache).
 *
 * - GET /rewards: My rewards, those still to redeem (C9, first cut).
 * - POST /rewards/{reward}/redeem: "Redeem now" (C3, My rewards, try again)
 *   opens the redeem window (OpenRedeemWindow), then the redeem screen. A
 *   refusal (an unverified email, a reward already redeemed) changes nothing:
 *   the redeem screen says why.
 * - GET /rewards/{reward}/redeem: the redeem screen (C4): tap the stamper
 *   while the window is open, then the redemption it saw.
 * - DELETE /rewards/{reward}/redeem: Back from the redeem screen closes the
 *   window (CloseRedeemWindow), so the next tap stamps again.
 */
final class RewardController extends Controller
{
    public function index(Request $request, ListCustomerRewards $listCustomerRewards): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        return Inertia::render('rewards/index', ['rewards' => $listCustomerRewards->handle($user)]);
    }

    public function redeem(CustomerRewardRequest $request, OpenRedeemWindow $openRedeemWindow): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        try {
            $openRedeemWindow->handle($request->reward(), $user);
        } catch (RedeemRefused) {
            // The redeem screen shows why: a verify prompt, or the redemption already made.
        }

        return to_route('rewards.redeem.show', $request->reward()->id);
    }

    public function close(CustomerRewardRequest $request, CloseRedeemWindow $closeRedeemWindow): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $closeRedeemWindow->handle($request->reward(), $user);

        return to_route('rewards.index');
    }

    public function show(CustomerRewardRequest $request, DescribeCustomerReward $describeCustomerReward): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        // status: Fortify's "verification-link-sent", after a new link from the verify prompt.
        return Inertia::render('rewards/redeem', [
            'reward' => $describeCustomerReward->handle($request->reward(), $user),
            'status' => $request->session()->get('status'),
        ]);
    }
}
