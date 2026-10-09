<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Http\Middleware\ResolveOnboardingBusiness;
use App\Models\Business;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A wizard action with no fields (skipping the logo, cancelling setup):
 * BusinessPolicy::update on the business being set up. With none, there is
 * nothing to act on, and the controller sends the user to the dashboard.
 */
class OnboardingRequest extends FormRequest
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
        return [];
    }
}
