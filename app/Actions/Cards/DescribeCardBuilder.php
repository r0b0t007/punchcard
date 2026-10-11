<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Enums\CardMode;
use App\Enums\RewardType;
use App\Models\Business;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Policies\Invariants;
use App\Support\Cards\ProgressiveTiers;
use App\Support\Tenancy\TenantContext;

/**
 * The card builder page (CHW-32, B6), for a new card or one of the
 * organization's: the form's fields (CardRequest's names), whether customers
 * hold the card (its mode and tiers are then fixed and its stamps only go
 * down) and what the live preview needs: the name customers see (the
 * business being worked in, else the organization), the logo and the
 * organization's colour, which a card without its own shows (ADR 0007).
 * A new card starts as the onboarding wizard's does: 10 stamps for a free
 * item, with the database's cooldown and daily cap.
 */
final readonly class DescribeCardBuilder
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{
     *     card: array{id: int, active: bool, held: bool, bannerUrl: ?string}|null,
     *     fields: array<string, mixed>,
     *     preview: array{businessName: string, logoUrl: ?string, organizationColor: ?string},
     * }
     */
    public function handle(Organization $organization, ?LoyaltyCard $card): array
    {
        $businessId = $this->context->businessId();
        $business = $businessId === null ? null : Business::query()->whereKey($businessId)->first();

        return [
            'card' => $card instanceof LoyaltyCard ? [
                'id' => $card->id,
                'active' => $card->active,
                'held' => Invariants::isHeld($card),
                'bannerUrl' => $card->bannerUrl(),
            ] : null,
            'fields' => $card instanceof LoyaltyCard ? $this->fieldsOf($card) : $this->defaults(),
            'preview' => [
                'businessName' => $business->name ?? $organization->name,
                'logoUrl' => $organization->logoUrl(),
                'organizationColor' => $organization->brand_color,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'name' => '',
            'mode' => CardMode::Cyclic->value,
            'stamps_required' => 10,
            'reward_type' => RewardType::Item->value,
            'reward_value' => null,
            'reward_text' => '',
            'tiers' => [],
            'brand_color' => null,
            'stamp_style' => 'dot',
            'info' => ['hours' => '', 'phone' => '', 'links' => []],
            'terms' => '',
            'cooldown_min' => 20,
            'daily_cap' => 5,
        ];
    }

    /** @return array<string, mixed> */
    private function fieldsOf(LoyaltyCard $card): array
    {
        return [
            'name' => $card->name,
            'mode' => $card->mode->value,
            'stamps_required' => $card->stamps_required,
            'reward_type' => $card->reward_type->value,
            'reward_value' => $card->reward_value,
            'reward_text' => $card->reward_text,
            'tiers' => $card->mode === CardMode::Progressive ? ProgressiveTiers::parse($card->tiers) : [],
            'brand_color' => $card->brandColor(),
            'stamp_style' => $card->stamp_style ?? 'dot',
            'info' => [
                'hours' => $card->info['hours'] ?? '',
                'phone' => $card->info['phone'] ?? '',
                'links' => $card->info['links'] ?? [],
            ],
            'terms' => $card->terms ?? '',
            'cooldown_min' => $card->cooldown_min,
            'daily_cap' => $card->daily_cap,
        ];
    }
}
