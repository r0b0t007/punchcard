<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Enums\CustomerSegment;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The customers list (CHW-44, B4) of whoever runs the current business: a
 * tab, a first name to look for, a page.
 */
class CustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $business = $this->business();

        return $business instanceof Business && Gate::allows('viewAny', [BusinessCustomer::class, $business]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'segment' => ['sometimes', Rule::enum(CustomerSegment::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:60'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function business(): ?Business
    {
        return app(TenantContext::class)->business();
    }

    public function tab(): CustomerSegment
    {
        return CustomerSegment::tryFrom($this->string('segment')->toString()) ?? CustomerSegment::All;
    }

    /** What was typed (trimmed by TrimStrings); null for nothing. */
    public function search(): ?string
    {
        $search = $this->string('q')->toString();

        return $search === '' ? null : $search;
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }
}
