<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Exporting the current business's customers and stamps (CHW-49, design 21): its owner only
 * (BusinessCustomerPolicy::export), for some of its days: from and to, to no later than today in the business's
 * time, at most MAX_YEARS apart.
 */
final class ExportCustomersRequest extends FormRequest
{
    public const int MAX_YEARS = 3;

    public function authorize(): bool
    {
        $business = $this->business();

        return $business instanceof Business && Gate::allows('export', [BusinessCustomer::class, $business]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->business() instanceof Business) {
                return;
            }

            $timezone = LocalMoment::timezoneOfBusiness($this->business()->id);
            $today = CarbonImmutable::now($timezone)->format('Y-m-d');

            if ($this->to() > $today) {
                $validator->errors()->add('to', __('Pick a day up to today.'));
            } elseif (CarbonImmutable::parse($this->from())->addYears(self::MAX_YEARS)->format('Y-m-d') < $this->to()) {
                $validator->errors()->add('to', __('Up to :count years at a time.', ['count' => self::MAX_YEARS]));
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['from' => __('From'), 'to' => __('To')];
    }

    public function business(): ?Business
    {
        return app(TenantContext::class)->business();
    }

    public function from(): string
    {
        return $this->string('from')->toString();
    }

    public function to(): string
    {
        return $this->string('to')->toString();
    }
}
