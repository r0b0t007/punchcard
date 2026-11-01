<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Campaigns\CampaignRefused;
use App\Actions\Campaigns\CreateCampaign;
use App\Http\Requests\Campaigns\StoreCampaignRequest;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Campaigns (CHW-42, B9), whoever runs the business's (owner or org admin),
 * never its staff's:
 *
 * - POST /business/campaigns: a notification to an audience of its
 *   followers, now (CreateCampaign), within its weekly limit.
 *
 * The composer and the campaigns page come with the next part of CHW-42.
 */
final class CampaignController extends Controller
{
    public function store(StoreCampaignRequest $request, CreateCampaign $createCampaign): RedirectResponse
    {
        /** @var Business $business authorized by the request */
        $business = $request->business();
        /** @var User $user */
        $user = $request->user();

        try {
            $campaign = $createCampaign->handle($business, $user, $request->string('idempotency_key')->toString(), $request->audience(), $request->string('title')->toString(), $request->string('body')->toString(), $request->cardId());
        } catch (CampaignRefused $refused) {
            return back()->withErrors(['campaign' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Sent to :count person.|Sent to :count people.', $campaign->audience_count)]);

        return to_route('dashboard');
    }
}
