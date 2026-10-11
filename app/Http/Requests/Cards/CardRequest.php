<?php

declare(strict_types=1);

namespace App\Http\Requests\Cards;

use App\Enums\CardMode;
use App\Enums\RewardType;
use App\Enums\StampStyle;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A card from the card builder (CHW-32, B6): LoyaltyCardPolicy::update on
 * the card, or ::create in the organization being worked in for a new one.
 * The same limits as the database (5 to 50 stamps, a positive reward value,
 * at most 100 %) and as ProgressiveTiers (each tier its own stamps count and
 * a reward). A progressive card takes its stamps and reward from its top
 * tier, which needs 5 to 50 stamps; the other mode's fields the form still
 * holds are left out. Links are http(s) only: they show to
 * customers. With publish, the card runs once saved ("Publish" in the
 * builder, not "Save draft").
 */
class CardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $card = $this->route('card');

        if ($card instanceof LoyaltyCard) {
            return $this->user()?->can('update', $card) === true;
        }

        $organization = $this->organization();

        return $organization instanceof Organization && $this->user()?->can('create', [LoyaltyCard::class, $organization]) === true;
    }

    /** The organization being worked in, a new card's. */
    public function organization(): ?Organization
    {
        $organizationId = app(TenantContext::class)->organizationId();

        return $organizationId === null ? null : Organization::query()->find($organizationId);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $cyclic = Rule::requiredIf(fn (): bool => $this->input('mode') === CardMode::Cyclic->value);
        $valued = Rule::requiredIf(fn (): bool => $this->input('mode') === CardMode::Cyclic->value
            && in_array($this->input('reward_type'), [RewardType::Percent->value, RewardType::Fixed->value], true));

        return [
            'name' => ['required', 'string', 'max:80'],
            'mode' => ['required', Rule::enum(CardMode::class)],
            'stamps_required' => ['exclude_if:mode,progressive', $cyclic, 'nullable', 'integer', 'between:5,50'],
            'reward_type' => ['exclude_if:mode,progressive', $cyclic, 'nullable', Rule::enum(RewardType::class)],
            'reward_value' => ['exclude_if:mode,progressive', $valued, 'nullable', 'integer', 'min:1', $this->input('reward_type') === RewardType::Percent->value ? 'max:100' : 'max:100000000'],
            'reward_text' => ['exclude_if:mode,progressive', $cyclic, 'nullable', 'string', 'max:120'],
            'tiers' => ['exclude_unless:mode,progressive', Rule::requiredIf(fn (): bool => $this->input('mode') === CardMode::Progressive->value), 'nullable', 'array', 'min:1', 'max:10'],
            'tiers.*.stamps' => ['required', 'integer', 'between:1,50', 'distinct'],
            'tiers.*.reward' => ['required', 'string', 'max:120'],
            'brand_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/D'],
            'stamp_style' => ['nullable', Rule::enum(StampStyle::class)],
            'info' => ['nullable', 'array'],
            'info.hours' => ['nullable', 'string', 'max:200'],
            'info.phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]+$/D'],
            'info.links' => ['nullable', 'array', 'max:5'],
            'info.links.*.label' => ['required', 'string', 'max:40'],
            'info.links.*.url' => ['required', 'string', 'max:255', 'url:http,https'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'cooldown_min' => ['required', 'integer', 'between:0,1440'],
            'daily_cap' => ['nullable', 'integer', 'between:1,100'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A progressive card's top tier is its whole card: 5 to 50 stamps, as for a cyclic one.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $tiers = $this->input('tiers');

            if ($this->input('mode') !== CardMode::Progressive->value || ! is_array($tiers) || $validator->errors()->isNotEmpty()) {
                return;
            }

            if (max([0, ...array_map(fn (mixed $tier): int => is_array($tier) ? (int) ($tier['stamps'] ?? 0) : 0, $tiers)]) < 5) {
                $validator->errors()->add('tiers', __('The last tier needs at least 5 stamps.'));
            }
        }];
    }
}
