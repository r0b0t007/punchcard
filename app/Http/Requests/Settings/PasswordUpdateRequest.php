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
            ...$this->user()?->hasPassword() !== true ? [] : ['current_password' => $this->currentPasswordRules()],
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        // Without a password, having confirmed it's them lately stands in for the current one (CHW-23).
        return [RecentConfirmation::rule($this->session(), 'password', $this->user()?->hasPassword() === false)];
    }
}
