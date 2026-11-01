<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Campaigns\CampaignRefused;
use App\Actions\Campaigns\CreateCampaign;
use App\Actions\Campaigns\DescribeCampaigns;
use App\Actions\Campaigns\DescribeComposer;
use App\Http\Requests\Campaigns\StoreCampaignRequest;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Campaigns (CHW-42, B9), whoever runs the business's (owner or org admin),
 * never its staff's:
 *
 * - GET /business/campaigns: its latest campaigns, and how many of the
 *   week's are left (DescribeCampaigns);
 * - GET /business/campaigns/create: the composer, with each audience's
 *   size and who would get it (DescribeComposer);
 * - POST /business/campaigns: a notification to an audience of its
 *   followers, now (CreateCampaign), within its weekly limit.
 */
final class CampaignController extends Controller
{
    public function index(TenantContext $context, DescribeCampaigns $describeCampaigns): Response
    {
        $business = $context->business() ?? abort(403);
        Gate::authorize('viewAny', [Campaign::class, $business]);

        return Inertia::render('business/campaigns/index', $describeCampaigns->handle($business));
    }

    public function create(TenantContext $context, DescribeComposer $describeComposer): Response
    {
        $business = $context->business() ?? abort(403);
        Gate::authorize('create', [Campaign::class, $business]);

        return Inertia::render('business/campaigns/create', $describeComposer->handle($business));
    }

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

        return to_route('campaigns.index');
    }
}
