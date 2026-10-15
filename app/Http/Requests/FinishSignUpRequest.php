<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new customer's first details, after their first email link (CHW-23):
 * their name, that they are 16 or older (required: no account otherwise),
 * and news by email, only if ticked.
 */
class FinishSignUpRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'adult' => ['accepted'],
            'marketing' => ['sometimes', 'boolean'],
        ];
    }

    public function wantsNews(): bool
    {
        return $this->boolean('marketing');
    }
}
