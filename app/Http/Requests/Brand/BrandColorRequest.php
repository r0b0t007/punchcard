<?php

declare(strict_types=1);

namespace App\Http\Requests\Brand;

use Illuminate\Contracts\Validation\ValidationRule;

/** The brand colour (B17): #RRGGBB, as the card builder takes it, or null for punchcard's espresso. */
class BrandColorRequest extends BrandRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'brand_color' => ['present', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/D'],
        ];
    }
}
