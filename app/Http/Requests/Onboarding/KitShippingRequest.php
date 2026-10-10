<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Http\Middleware\ResolveOnboardingBusiness;
use App\Models\Business;
use App\Models\KitOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The wizard's shipping step (CHW-31): KitOrderPolicy::update on the
 * business's requested order, or ::create for a first one. A phone number the courier can call: digits, spaces and the
 * usual separators, with an optional leading +.
 */
class KitShippingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $business = ResolveOnboardingBusiness::of($this);

        if (! $business instanceof Business) {
            return false;
        }

        $order = KitOrder::query()->where('business_id', $business->id)->requested()->first();

        return $order instanceof KitOrder
            ? $this->user()?->can('update', $order) === true
            : $this->user()?->can('create', [KitOrder::class, $business]) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ().-]{5,30}$/'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:16'],
        ];
    }

    /**
     * @return array{recipient_name: string, phone: string, address: string, city: string, postal_code: ?string}
     */
    public function shipping(): array
    {
        $postalCode = $this->input('postal_code');

        return [
            'recipient_name' => $this->string('recipient_name')->toString(),
            'phone' => $this->string('phone')->toString(),
            'address' => $this->string('address')->toString(),
            'city' => $this->string('city')->toString(),
            'postal_code' => is_string($postalCode) ? $postalCode : null,
        ];
    }
}
