<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Actions\Account\MustHandOverBusiness;
use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * An owner or org admin hands over or closes their business first.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(MustHandOverBusiness $mustHandOverBusiness): array
    {
        return [
            function (Validator $validator) use ($mustHandOverBusiness): void {
                $user = $this->user();

                if ($user instanceof User && $mustHandOverBusiness->handle($user)) {
                    $validator->errors()->add('account', __('Hand over or close your business before deleting your account: contact support to do so.'));
                }
            },
        ];
    }
}
