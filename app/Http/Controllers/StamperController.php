<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Stampers\ChangeStamperStatus;
use App\Actions\Stampers\DescribeStampersPage;
use App\Actions\Stampers\RejectedTaps;
use App\Actions\Stampers\StamperRefused;
use App\Enums\StamperStatus;
use App\Http\Requests\Stampers\ChangeStamperStatusRequest;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The stampers page (B12, CHW-36), whoever runs the business's (owner or
 * org admin), never its staff's:
 *
 * - GET /business/stampers: each open site's stampers (DescribeStampersPage)
 *   and the taps refused in the last 30 days (RejectedTaps).
 * - PATCH /business/stampers/{stamper}/status: off at once, or on again
 *   (ChangeStamperStatus); never one the platform holds while its keys are
 *   changed.
 *
 * {stamper} resolves in the tenant: another business's is a 404.
 */
final class StamperController extends Controller
{
    public function index(TenantContext $context, DescribeStampersPage $describeStampersPage, RejectedTaps $rejectedTaps): Response
    {
        $business = $context->business() ?? abort(403);
        Gate::authorize('viewAny', [Stamper::class, $business]);

        return Inertia::render('business/stampers', [
            ...$describeStampersPage->handle($business),
            'refused' => $rejectedTaps->handle($business),
        ]);
    }

    public function status(ChangeStamperStatusRequest $request, int $stamper, TenantContext $context, ChangeStamperStatus $changeStamperStatus): RedirectResponse
    {
        $context->business() ?? abort(403);
        $current = Stamper::query()->current()->whereKey($stamper)->first() ?? abort(404);
        Gate::authorize('update', $current);

        try {
            $changed = $changeStamperStatus->handle($current, $request->status());
        } catch (StamperRefused $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refused->getMessage()]);

            return to_route('stampers.index');
        }

        $name = $changed->label ?? __('Stamper :id', ['id' => $changed->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed->status === StamperStatus::Disabled
            ? __(':stamper is switched off: a tap on it is refused.', ['stamper' => $name])
            : __(':stamper is switched on again.', ['stamper' => $name])]);

        return to_route('stampers.index');
    }
}
