<?php

declare(strict_types=1);

namespace App\Http\Requests\Brand;

use Illuminate\Contracts\Validation\ValidationRule;

/** The cover (B17): a card banner's rules (CardBannerRequest), wide enough for the top of a tap screen. */
class BrandCoverRequest extends BrandRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cover' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=600,min_height=200,max_width=4000,max_height=4000'],
        ];
    }
}
