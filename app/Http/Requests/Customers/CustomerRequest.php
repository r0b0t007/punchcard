<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Actions\Customers\DescribeCustomer;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * One customer (CHW-44, B5), for whoever runs the current business: the
 * business's own, looked up in its tenant (another's is not found), and how
 * much of their history to show.
 */
class CustomerRequest extends FormRequest
{
    private ?Business $business = null;

    private ?BusinessCustomer $customer = null;

    public function authorize(): bool
    {
        return Gate::allows('view', $this->customer());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shown' => ['sometimes', 'integer', 'min:'.DescribeCustomer::SHOWN, 'max:'.DescribeCustomer::MOST],
        ];
    }

    /** The business worked in, read once; null at network level. */
    public function business(): ?Business
    {
        return $this->business ??= app(TenantContext::class)->business();
    }

    /**
     * The business's own customer, or a 404. Business only, on purpose: stamps, takings back and notes go through
     * here, and a franchise's head office, working across its sites, only reads them (ShowCustomerRequest, CHW-365).
     * Its policies would let it run any of its businesses, so at network level no customer is found here at all.
     */
    public function customer(): BusinessCustomer
    {
        $businessId = app(TenantContext::class)->businessId();

        abort_if($businessId === null, 404);

        return $this->customer ??= BusinessCustomer::query()
            ->where('business_id', $businessId)
            ->whereKey((int) $this->route('customer'))
            ->first() ?? abort(404);
    }

    public function shown(): int
    {
        return $this->integer('shown', DescribeCustomer::SHOWN);
    }
}
