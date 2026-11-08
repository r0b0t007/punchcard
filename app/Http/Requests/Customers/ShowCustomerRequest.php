<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Models\Business;
use App\Models\OrganizationCustomer;
use Illuminate\Support\Facades\Gate;

/**
 * Opening one customer (B5): inside a business, the business's own (CustomerRequest, CHW-44); across a franchise's
 * sites, for its head office (CHW-365), one of the organization's, looked up in its tenant (another organization's
 * is not found). Only opening: stamps and notes stay inside a business (CustomerRequest), so at network level they
 * find no customer.
 */
class ShowCustomerRequest extends CustomerRequest
{
    private ?OrganizationCustomer $networkCustomer = null;

    public function authorize(): bool
    {
        return $this->atNetwork() ? Gate::allows('view', $this->networkCustomer()) : parent::authorize();
    }

    /** Working across the organization rather than inside one of its businesses. */
    public function atNetwork(): bool
    {
        return ! $this->business() instanceof Business;
    }

    public function networkCustomer(): OrganizationCustomer
    {
        return $this->networkCustomer ??= OrganizationCustomer::query()->whereKey((int) $this->route('customer'))->first() ?? abort(404);
    }
}
