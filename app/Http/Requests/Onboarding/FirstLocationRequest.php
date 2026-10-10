<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Http\Middleware\ResolveOnboardingBusiness;
use App\Models\Business;
use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** The wizard's location step (CHW-31): LocationPolicy::create on the business being set up. */
class FirstLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $business = ResolveOnboardingBusiness::of($this);

        return $business instanceof Business && $this->user()?->can('create', [Location::class, $business]) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
        ];
    }
}
