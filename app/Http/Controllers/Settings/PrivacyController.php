<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Account\DescribePrivacy;
use App\Actions\Account\ExportMyData;
use App\Actions\Account\SetMarketingConsent;
use App\Actions\Cards\SetFollow;
use App\Actions\Push\SetRewardNotifications;
use App\Http\Controllers\Controller;
use App\Http\Requests\FollowRequest;
use App\Http\Requests\Settings\MarketingConsentRequest;
use App\Models\ConsentEvent;
use App\Models\User;
use App\Support\Auth\RecentConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
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
    /** Download my data needs a confirmation this recent (RequirePassword on its routes). */
    public const int EXPORT_CONFIRM_SECONDS = 900;

    public function edit(Request $request, DescribePrivacy $describePrivacy): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/privacy', [
            ...$describePrivacy->handle($user),
            // Confirmed lately enough to download (privacy.confirm), else the button confirms first.
            // The token goes in the download's plain form (a POST page load, not an Inertia visit).
            'download' => ['ready' => RecentConfirmation::holds($request->session(), self::EXPORT_CONFIRM_SECONDS), 'token' => csrf_token()],
            // "A new link is on its way" after Send the link again (verification.send), or "export-limited".
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

    /**
     * Download my data: one JSON file, never cached, after a confirmation in
     * the last 15 minutes and within the hourly limit (routes).
     */
    public function export(Request $request, ExportMyData $exportMyData): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $now = CarbonImmutable::now();

        // A file to save, never a page to show: some browsers (WebKit on Linux) display application/json in place even
        // as an attachment, which in the installed app leaves no way back.
        return response()->json($exportMyData->handle($user, $now), 200, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment; filename="punchcard-data-'.$now->utc()->toDateString().'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** "Rewards ready", on or off, for every phone with notifications on (CHW-41, 11c). */
    public function updateNotifications(Request $request, SetRewardNotifications $setRewardNotifications): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $setRewardNotifications->handle($user, (bool) $request->validate(['rewards' => ['required', 'boolean']])['rewards']);

        return to_route('privacy.edit');
    }

    /** A place's offers, followed or left from here: the same Follow as on the card's page (C6), on one of their own cards. */
    public function follow(FollowRequest $request, SetFollow $setFollow): RedirectResponse
    {
        $setFollow->handle($request->enrollment(), $request->boolean('follow'), ConsentEvent::SOURCE_SETTINGS);

        return to_route('privacy.edit');
    }
}
