<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Enums\CardMode;
use App\Enums\RewardType;
use App\Models\Business;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Support\Branding\Contrast;
use App\Support\Cards\ProgressiveTiers;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a card from the card builder (CHW-32, B6), its fields
 * validated by CardRequest.
 *
 * - A progressive card takes its stamps count and reward from its top tier;
 *   a free item has no value.
 * - A brand colour is stored with the text colour that reads on it
 *   (Contrast, ADR 0007); without one, the card shows the organization's.
 * - A new card starts paused, a draft, honoured by every operating business
 *   of the organization: publishing it (SetCardActive) offers it there.
 * - Once customers hold the card, its mode and tiers are fixed and its
 *   stamps count only goes down (LoyaltyCard's guard): the form gets the
 *   reason instead of an error. The card is locked first, so a first
 *   enrollment racing the change commits before the check or waits.
 *
 * Runs in the tenant, as its org admin: LoyaltyCard's guard decides.
 */
final readonly class SaveCard
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $fields  CardRequest::validated()
     */
    public function handle(Organization $organization, ?LoyaltyCard $card, array $fields): LoyaltyCard
    {
        $attributes = $this->attributes($fields);

        return DB::transaction(function () use ($organization, $card, $attributes): LoyaltyCard {
            if (! $card instanceof LoyaltyCard) {
                $card = (new LoyaltyCard)->forceFill([...$attributes, 'organization_id' => $organization->id, 'active' => false]);
                $card->save();

                $businesses = $this->context->bypass(fn (): array => Business::query()
                    ->where('organization_id', $organization->id)->operating()->orderBy('id')->pluck('id')->all());
                $card->businesses()->attach($businesses);

                return $card;
            }

            $locked = LoyaltyCard::query()->lockForUpdate()->findOrFail($card->id);

            if ($this->context->bypass(fn (): bool => LoyaltyCard::query()->whereKey($locked->id)->held()->exists())) {
                $this->assertHeldChange($locked, $attributes);
                // Unchanged, so never set again (a reordered copy would read as a change).
                unset($attributes['mode'], $attributes['tiers']);
            }

            $locked->forceFill($attributes)->save();

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function attributes(array $fields): array
    {
        $mode = CardMode::from((string) $fields['mode']);
        $tiers = $mode === CardMode::Progressive ? ProgressiveTiers::parse(array_map(
            fn (array $tier): array => ['stamps' => (int) $tier['stamps'], 'reward' => trim((string) $tier['reward'])],
            (array) $fields['tiers'],
        )) : null;
        $top = $tiers === null ? null : $tiers[array_key_last($tiers)];
        $rewardType = $top === null ? RewardType::from((string) $fields['reward_type']) : RewardType::Item;
        $brand = isset($fields['brand_color']) ? strtoupper((string) $fields['brand_color']) : null;

        return [
            'name' => trim((string) $fields['name']),
            'mode' => $mode,
            'tiers' => $tiers,
            'stamps_required' => $top['stamps'] ?? (int) $fields['stamps_required'],
            'reward_type' => $rewardType,
            'reward_value' => $rewardType === RewardType::Item ? null : (int) $fields['reward_value'],
            'reward_text' => $top['reward'] ?? trim((string) $fields['reward_text']),
            'colors' => $brand === null ? null : ['brand' => $brand, 'foreground' => Contrast::readableForeground($brand)],
            'stamp_style' => $fields['stamp_style'] ?? null,
            'info' => $this->info($fields['info'] ?? null),
            'terms' => isset($fields['terms']) ? trim((string) $fields['terms']) : null,
            'cooldown_min' => (int) $fields['cooldown_min'],
            'daily_cap' => isset($fields['daily_cap']) ? (int) $fields['daily_cap'] : null,
        ];
    }

    /**
     * Hours, phone and links, without the empty ones; null when nothing is left.
     *
     * @return array{hours?: string, phone?: string, links?: list<array{label: string, url: string}>}|null
     */
    private function info(mixed $info): ?array
    {
        if (! is_array($info)) {
            return null;
        }

        $kept = array_filter([
            'hours' => trim((string) ($info['hours'] ?? '')),
            'phone' => trim((string) ($info['phone'] ?? '')),
        ], fn (string $value): bool => $value !== '');

        $links = array_map(
            fn (array $link): array => ['label' => trim((string) $link['label']), 'url' => trim((string) $link['url'])],
            array_values((array) ($info['links'] ?? [])),
        );

        if ($links !== []) {
            $kept['links'] = $links;
        }

        return $kept === [] ? null : $kept;
    }

    /**
     * What customers holding the card keep: its mode and tiers, and a stamps
     * count no higher than now (lowering pays them sooner, AddStamps).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertHeldChange(LoyaltyCard $card, array $attributes): void
    {
        $storedTiers = $card->mode === CardMode::Progressive ? ProgressiveTiers::parse($card->tiers) : null;

        if ($attributes['mode'] !== $card->mode || $attributes['tiers'] !== $storedTiers) {
            throw ValidationException::withMessages(['mode' => __('Customers hold this card: its mode and tiers are fixed.')]);
        }

        if ($attributes['stamps_required'] > $card->stamps_required) {
            throw ValidationException::withMessages([
                'stamps_required' => __('Customers hold this card: its stamps can be lowered, never raised. Start a new card instead.'),
            ]);
        }
    }
}
