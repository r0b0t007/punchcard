<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Customers\ListCustomers;
use App\Http\Requests\Customers\CustomersRequest;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A business's customers (CHW-44), for whoever runs it:
 *
 * - GET /business/customers: the list (B4), by tab, first name and page.
 */
final class CustomerController extends Controller
{
    public function index(CustomersRequest $request, ListCustomers $listCustomers): Response
    {
        /** @var Business $business authorized by the request */
        $business = $request->business();

        return Inertia::render('business/customers/index', $listCustomers->handle($business, $request->tab(), $request->search(), $request->page(), CarbonImmutable::now()));
    }
}
