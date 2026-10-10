<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Http\Middleware\ResolveOnboardingBusiness;
use App\Models\Business;
use App\Models\LoyaltyCard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The wizard's card step (CHW-31): LoyaltyCardPolicy::update on the card
 * the business honours, or ::create in its organization for a first one. A card of 5 to 50 stamps, as the database checks.
 */
class FirstCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $business = ResolveOnboardingBusiness::of($this);

        if (! $business instanceof Business) {
            return false;
        }

        $card = LoyaltyCard::query()->honouredBy($business->id)->first();

        return $card instanceof LoyaltyCard
            ? $this->user()?->can('update', $card) === true
            : $this->user()?->can('create', [LoyaltyCard::class, $business->organization]) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reward_text' => ['required', 'string', 'max:120'],
            'stamps_required' => ['required', 'integer', 'between:5,50'],
        ];
    }
}
