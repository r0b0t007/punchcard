<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Counter\ArmStamper;
use App\Actions\Counter\DescribeCounter;
use App\Actions\Counter\DisarmStamper;
use App\Http\Requests\Counter\ArmStamperRequest;
use App\Models\Stamper;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Counter mode (CHW-29, S1), for whoever works in the business, owner or
 * staff:
 *
 * - GET /business/counter: the stampers they may arm (DescribeCounter).
 * - POST /business/counter/stampers/{stamper}/arm (throttled): "Next tap
 *   gives" (ArmStamper).
 * - DELETE /business/counter/stampers/{stamper}/arm: "Cancel" (DisarmStamper).
 *
 * Arming is StamperPolicy::arm: staff limited to one location, there only.
 * {stamper} resolves in the tenant: another business's is a 404. No business
 * worked in (a customer, an org admin across the organization): a 403.
 */
final class CounterController extends Controller
{
    public function show(TenantContext $context, DescribeCounter $describeCounter): Response
    {
        // A business worked in means a member of it (ResolveTenant): its owner, its staff, or HQ.
        $business = $context->business() ?? abort(403);

        return Inertia::render('business/counter', $describeCounter->handle($business, $context->locationId()));
    }

    public function arm(ArmStamperRequest $request, int $stamper, ArmStamper $armStamper): RedirectResponse
    {
        $current = $this->stamper($stamper);
        Gate::authorize('arm', $current);

        /** @var User $user */
        $user = $request->user();

        if (! $armStamper->handle($current, $request->qty(), $user)) {
            // A toast, not a form error: the next poll would clear that before staff at arm's length read it.
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This stamper can\'t be armed now: it is off, or the site is closed.')]);
        }

        return to_route('counter.show');
    }

    public function disarm(int $stamper, DisarmStamper $disarmStamper): RedirectResponse
    {
        $current = $this->stamper($stamper);
        Gate::authorize('arm', $current);

        $disarmStamper->handle($current);

        return to_route('counter.show');
    }

    private function stamper(int $id): Stamper
    {
        return Stamper::query()->current()->whereKey($id)->first() ?? abort(404);
    }
}
