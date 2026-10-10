<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Onboarding\DescribeChecklist;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Support\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dashboard. For the owner of the current business, the setup
 * checklist (CHW-31, DescribeChecklist); the owner dashboard itself is
 * CHW-33. Customers and staff get no checklist.
 */
final class DashboardController extends Controller
{
    public function show(TenantContext $context, DescribeChecklist $describeChecklist): Response
    {
        $business = $context->businessRole() === BusinessRole::Owner && $context->businessId() !== null
            ? Business::query()->find($context->businessId())
            : null;

        return Inertia::render('dashboard', [
            'checklist' => $business instanceof Business ? $describeChecklist->handle($business) : null,
        ]);
    }
}
