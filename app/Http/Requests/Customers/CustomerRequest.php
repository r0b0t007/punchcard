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

    public function business(): ?Business
    {
        return app(TenantContext::class)->business();
    }

    public function customer(): BusinessCustomer
    {
        return $this->customer ??= BusinessCustomer::query()
            ->where('business_id', $this->business()?->id)
            ->whereKey((int) $this->route('customer'))
            ->first() ?? abort(404);
    }

    public function shown(): int
    {
        return $this->integer('shown', DescribeCustomer::SHOWN);
    }
}
