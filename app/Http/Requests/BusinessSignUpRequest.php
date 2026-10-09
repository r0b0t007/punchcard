<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The business sign-up form (CHW-31): the business's name here; the account
 * fields are validated as any registration's (CreateNewUser). Open to
 * guests, so no authorization beyond the route's.
 */
class BusinessSignUpRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * The account fields, the email lowercased as Fortify's registration does
     * (fortify.lowercase_usernames).
     *
     * @return array<string, string>
     */
    public function account(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->lower()->toString(),
            'password' => $this->string('password')->toString(),
            'password_confirmation' => $this->string('password_confirmation')->toString(),
        ];
    }
}
