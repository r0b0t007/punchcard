<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\Auth\RecentConfirmation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->profileRules($this->user()->id);
    }

    /**
     * A new address only once confirmed it's them lately (RecentConfirmation,
     * CHW-23): the address is what email links and resets go to, so a stale
     * or borrowed session must not move the account.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        $email = $this->input('email');

        return [RecentConfirmation::rule($this->session(), 'email', is_string($email) && $email !== $this->user()?->email)];
    }

    /** The address as accounts store it (User::normalizeEmail), so a case variant of a taken one is refused here. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => User::normalizeEmail($this->input('email'))]);
        }
    }
}
