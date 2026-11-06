<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Automations\DescribeAutomations;
use App\Actions\Automations\SaveAutomation;
use App\Http\Requests\Automations\SaveAutomationRequest;
use App\Models\Automation;
use App\Models\Business;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Automations (CHW-46, B11), whoever runs the business's (owner or org
 * admin), never its staff's, while it is allowed automations:
 *
 * - GET /business/automations: its three automations, each with its message
 *   and how many people it reached lately (DescribeAutomations);
 * - PATCH /business/automations/{trigger}: one turned on or off, its
 *   setting and its message (SaveAutomation).
 */
final class AutomationController extends Controller
{
    public function index(TenantContext $context, DescribeAutomations $describeAutomations): Response
    {
        $business = $context->business() ?? abort(403);
        Gate::authorize('viewAny', [Automation::class, $business]);

        return Inertia::render('business/automations', $describeAutomations->handle($business));
    }

    public function update(SaveAutomationRequest $request, SaveAutomation $saveAutomation): RedirectResponse
    {
        /** @var Business $business authorized by the request */
        $business = $request->business();
        $trigger = $request->trigger();
        ['automation' => $automation, 'wasActive' => $wasActive] = $saveAutomation->handle($business, $trigger, $request->values());

        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $automation->active && ! $wasActive => __(':automation is on.', ['automation' => $trigger->label()]),
            ! $automation->active && $wasActive => __(':automation is off.', ['automation' => $trigger->label()]),
            default => __('Saved.'),
        }]);

        return to_route('automations.index');
    }
}
