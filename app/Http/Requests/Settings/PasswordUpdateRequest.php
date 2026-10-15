<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Support\Auth\RecentConfirmation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // An account without a password (signed up by email link, CHW-23) chooses one, having confirmed it's them (after()).
        return [
            ...$this->user()?->password === null ? [] : ['current_password' => $this->currentPasswordRules()],
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->user()?->password === null && ! RecentConfirmation::holds($this->session())) {
                    $validator->errors()->add('password', __('Confirm it\'s you first.'));
                }
            },
        ];
    }
}
