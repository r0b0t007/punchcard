<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Account\DescribePrivacy;
use App\Actions\Account\SetMarketingConsent;
use App\Actions\Cards\SetFollow;
use App\Http\Controllers\Controller;
use App\Http\Requests\FollowRequest;
use App\Http\Requests\Settings\MarketingConsentRequest;
use App\Models\ConsentEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Privacy settings page (CHW-58, C12): what the signed-in person lets
 * punchcard, and the places they follow, send them. Their own account only, verified or not (turning news
 * off never needs a confirmed email).
 */
final class PrivacyController extends Controller
{
    public function edit(Request $request, DescribePrivacy $describePrivacy): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/privacy', [
            ...$describePrivacy->handle($user),
            // "A new link is on its way" after Send the link again (verification.send).
            'status' => $request->session()->get('status'),
        ]);
    }

    public function updateMarketing(MarketingConsentRequest $request, SetMarketingConsent $setMarketingConsent): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $setMarketingConsent->handle($user, $request->boolean('marketing'));

        return to_route('privacy.edit');
    }

    /** A place's offers, followed or left from here: the same Follow as on the card's page (C6), on one of their own cards. */
    public function follow(FollowRequest $request, SetFollow $setFollow): RedirectResponse
    {
        $setFollow->handle($request->enrollment(), $request->boolean('follow'), ConsentEvent::SOURCE_SETTINGS);

        return to_route('privacy.edit');
    }
}
