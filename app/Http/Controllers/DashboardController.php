<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Dashboard\DescribeDashboard;
use App\Actions\Onboarding\DescribeChecklist;
use App\Models\Business;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dashboard. For whoever runs the current business (its owner, or an
 * org admin working in it): the setup checklist (CHW-31,
 * DescribeChecklist) and the owner dashboard (CHW-33, DescribeDashboard),
 * for the business or one of its locations (?location=). Staff and
 * customers get neither. At the organization level HQ gets a note: the
 * network view is B18 (CHW-133).
 */
final class DashboardController extends Controller
{
    public function show(Request $request, TenantContext $context, DescribeChecklist $describeChecklist, DescribeDashboard $describeDashboard): Response
    {
        $business = $context->businessId() === null ? null : Business::query()->find($context->businessId());

        return Inertia::render('dashboard', [
            // Whoever runs the business (BusinessPolicy::update), as for the QR stand it links to. Closures: the
            // location filter's partial reload asks for the stats alone.
            'checklist' => fn (): ?array => $business instanceof Business && Gate::allows('update', $business) ? $describeChecklist->handle($business) : null,
            'stats' => fn (): ?array => $business instanceof Business && Gate::allows('viewStats', $business)
                ? $describeDashboard->handle($business, $request->integer('location') ?: null, CarbonImmutable::now())
                : null,
            'atOrganization' => ! $business instanceof Business && $context->organizationId() !== null,
        ]);
    }
}
