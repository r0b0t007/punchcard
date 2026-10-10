<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Enums\BusinessCategory;
use App\Http\Middleware\ResolveOnboardingBusiness;
use App\Models\Business;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The wizard's business step (CHW-31). Anyone signed in may start a
 * business; changing the one being set up takes BusinessPolicy::update.
 */
class BusinessDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $business = ResolveOnboardingBusiness::of($this);

        return ! $business instanceof Business || $this->user()?->can('update', $business) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::enum(BusinessCategory::class)],
        ];
    }
}
