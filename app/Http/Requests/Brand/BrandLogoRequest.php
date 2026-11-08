<?php

declare(strict_types=1);

namespace App\Http\Requests\Brand;

use Illuminate\Contracts\Validation\ValidationRule;

/** The logo (B17): the onboarding step's rules (LogoRequest), a JPEG, PNG or WebP image, never SVG. */
class BrandLogoRequest extends BrandRequest
{
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
