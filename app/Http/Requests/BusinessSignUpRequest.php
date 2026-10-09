<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * The business sign-up form (CHW-31): the account, with the same rules as
 * any registration's (CreateNewUser), and the business's name, all checked
 * in one round. Open to guests, so no authorization beyond the route's.
 */
class BusinessSignUpRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'business_name' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * The validated account fields, for CreateNewUser (which checks them again,
     * as for any registration): the confirmation is the password it matched.
     *
     * @return array<string, string>
     */
    public function account(): array
    {
        $account = $this->safe()->only(['name', 'email', 'password']);

        return [
            'name' => (string) $account['name'],
            'email' => (string) $account['email'],
            'password' => (string) $account['password'],
            'password_confirmation' => (string) $account['password'],
        ];
    }

    /** The email lowercased as Fortify's registration does, when fortify.lowercase_usernames says so. */
    protected function prepareForValidation(): void
    {
        if (config('fortify.lowercase_usernames') && is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }
    }
}
