<?php

declare(strict_types=1);

namespace App\Http\Requests\Cards;

use App\Models\LoyaltyCard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A card's banner (CHW-32, B6): LoyaltyCardPolicy::update. A JPEG, PNG or
 * WebP image (never SVG, which can carry scripts) of at most 2 MB, wide
 * enough for the card.
 */
class CardBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $card = $this->route('card');

        return $card instanceof LoyaltyCard && $this->user()?->can('update', $card) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'banner' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=600,min_height=200,max_width=4000,max_height=4000'],
        ];
    }
}
