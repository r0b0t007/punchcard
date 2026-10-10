<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Onboarding\DescribeChecklist;
use App\Models\Business;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dashboard. For whoever runs the current business (its owner, or an
 * org admin working in it), the setup checklist (CHW-31,
 * DescribeChecklist); the owner dashboard itself is CHW-33. Customers and
 * staff get no checklist.
 */
final class DashboardController extends Controller
{
    public function show(TenantContext $context, DescribeChecklist $describeChecklist): Response
    {
        $business = $context->businessId() === null ? null : Business::query()->find($context->businessId());

        return Inertia::render('dashboard', [
            // Whoever runs the business (BusinessPolicy::update), as for the QR stand it links to.
            'checklist' => $business instanceof Business && Gate::allows('update', $business) ? $describeChecklist->handle($business) : null,
        ]);
    }
}
