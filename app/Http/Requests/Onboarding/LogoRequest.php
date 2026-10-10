<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Http\Middleware\ResolveOnboardingBusiness;
use App\Models\Business;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The wizard's logo step (CHW-31): OrganizationPolicy::manageBrand. A JPEG,
 * PNG or WebP image (never SVG, which can carry scripts) of at most 2 MB,
 * large enough to print on the QR stand.
 */
class LogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $business = ResolveOnboardingBusiness::of($this);

        return $business instanceof Business && $this->user()?->can('manageBrand', $business->organization) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=128,min_height=128,max_width=4000,max_height=4000'],
        ];
    }
}
