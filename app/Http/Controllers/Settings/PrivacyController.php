<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Account\DescribePrivacy;
use App\Actions\Account\SetMarketingConsent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MarketingConsentRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Privacy settings page (CHW-58, C12): what the signed-in person lets
 * punchcard send them. Their own account only, verified or not (turning news
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
}
