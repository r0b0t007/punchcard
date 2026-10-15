<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use App\Support\Auth\RecentConfirmation;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }

    /**
     * For an account without a password (signed up by email link, CHW-23):
     * having confirmed it's them lately (RecentConfirmation) stands in for
     * the current password. An error on `password` otherwise.
     *
     * @return Closure(Validator): void
     */
    protected function confirmedInsteadOfPassword(?User $user, Session $session): Closure
    {
        return function (Validator $validator) use ($user, $session): void {
            if ($user instanceof User && ! $user->hasPassword() && ! RecentConfirmation::holds($session)) {
                $validator->errors()->add('password', __('Confirm it\'s you first.'));
            }
        };
    }
}
