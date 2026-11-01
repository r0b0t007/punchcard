<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Campaigns\CampaignRefused;
use App\Actions\Campaigns\CancelCampaign;
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
 *   followers, now or later (CreateCampaign), within its weekly limit;
 * - PATCH /business/campaigns/{campaign}/cancel: a scheduled one, until it
 *   goes (CancelCampaign).
 *
 * {campaign} resolves in the tenant: another business's is a 404.
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
            $campaign = $createCampaign->handle($business, $user, $request->string('idempotency_key')->toString(), $request->audience(), $request->string('title')->toString(), $request->string('body')->toString(), $request->cardId(), $request->sendAt());
        } catch (CampaignRefused $refused) {
            return back()->withErrors(['campaign' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $campaign->send_at->isFuture()
            ? trans_choice('Scheduled for :count person.|Scheduled for :count people.', $campaign->audience_count)
            : trans_choice('On its way to :count person.|On its way to :count people.', $campaign->audience_count)]);

        return to_route('campaigns.index');
    }

    public function cancel(int $campaign, CancelCampaign $cancelCampaign): RedirectResponse
    {
        $scheduled = Campaign::query()->whereKey($campaign)->first() ?? abort(404);
        Gate::authorize('cancel', $scheduled);

        Inertia::flash('toast', $cancelCampaign->handle($scheduled)
            ? ['type' => 'success', 'message' => __('Campaign canceled: nobody gets it.')]
            : ['type' => 'error', 'message' => __('This campaign is already on its way.')]);

        return to_route('campaigns.index');
    }
}
