<?php

declare(strict_types=1);

namespace App\Http\Requests\Cards;

use App\Models\LoyaltyCard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Publishing or pausing a card (CHW-32, B7): LoyaltyCardPolicy::update. */
class CardStatusRequest extends FormRequest
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
        return ['active' => ['required', 'boolean']];
    }
}
