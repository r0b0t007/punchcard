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
        return [
            ...$this->profileRules($this->user()->id),
            // Optional (CHW-46): the day and the month together, or neither.
            'birthday_day' => ['nullable', 'integer', 'between:1,31', 'required_with:birthday_month'],
            'birthday_month' => ['nullable', 'integer', 'between:1,12', 'required_with:birthday_day'],
        ];
    }

    /**
     * A new address only once confirmed it's them lately (RecentConfirmation,
     * CHW-23): the address is what email links and resets go to, so a stale
     * or borrowed session must not move the account. A birthday that is a
     * real day (CHW-46).
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        $email = $this->input('email');

        return [
            RecentConfirmation::rule($this->session(), 'email', is_string($email) && $email !== $this->user()?->email),
            // A day that month has in a leap year: 31 April is refused, 29 February is a birthday.
            function (Validator $validator): void {
                $day = $this->input('birthday_day');
                $month = $this->input('birthday_month');

                if (! $validator->errors()->hasAny(['birthday_day', 'birthday_month']) && is_numeric($day) && is_numeric($month) && ! checkdate((int) $month, (int) $day, 2000)) {
                    $validator->errors()->add('birthday_day', __('That date doesn’t exist. Choose another day.'));
                }
            },
        ];
    }

    /** The address as accounts store it (User::normalizeEmail), so a case variant of a taken one is refused here. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => User::normalizeEmail($this->input('email'))]);
        }
    }
}
