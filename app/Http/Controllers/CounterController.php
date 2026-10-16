<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Counter\ArmStamper;
use App\Actions\Counter\DescribeCounter;
use App\Actions\Counter\DisarmStamper;
use App\Actions\Stamps\RecentStamps;
use App\Http\Requests\Counter\ArmStamperRequest;
use App\Models\Stamper;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Counter mode (CHW-29, S1), for whoever works in the business, owner or
 * staff:
 *
 * - GET /business/counter: the stampers they may arm (DescribeCounter), and
 *   the latest stamps there (RecentStamps, by first name).
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
    public function show(TenantContext $context, DescribeCounter $describeCounter, RecentStamps $recentStamps): Response
    {
        // A business worked in means a member of it (ResolveTenant): its owner, its staff, or HQ.
        $business = $context->business() ?? abort(403);
        $locationId = $context->locationId();

        return Inertia::render('business/counter', [
            ...$describeCounter->handle($business, $locationId),
            // The live feed (S1): the latest stamps where they work (RecentStamps keeps a staff member to their
            // location), by first name; refreshed with the stampers.
            'feed' => fn (): array => $recentStamps->handle($business, $locationId, CarbonImmutable::now(), firstNames: true),
        ]);
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
