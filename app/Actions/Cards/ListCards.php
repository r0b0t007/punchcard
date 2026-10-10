<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Support\Cards\CardChoice;
use App\Support\Tenancy\TenantContext;

/**
 * The cards page (CHW-32, B7). For whoever runs the program (the org admin),
 * every card of the organization, with whether customers hold it and the
 * businesses honouring it; for a franchisee's owner, the cards its business
 * honours, read only, and nothing about who holds them. Working in a
 * business, the card new customers get there is marked (CardChoice with
 * nothing held). Running cards first, then the oldest.
 */
final readonly class ListCards
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return list<array{id: int, name: string, mode: string, modeLabel: string, rewardText: string, stampsRequired: int, active: bool, brandColor: ?string, held: ?bool, businesses: ?list<string>, forNewCustomers: bool}>
     */
    public function handle(bool $managesProgram): array
    {
        $businessId = $this->context->businessId();

        if (! $managesProgram && $businessId === null) {
            return [];
        }

        $query = $businessId === null || $managesProgram
            ? LoyaltyCard::query()->orderByDesc('active')->orderBy('id')
            : LoyaltyCard::query()->honouredBy($businessId);
        $cards = $query->get();

        $ids = $cards->modelKeys();
        $held = $managesProgram
            ? $this->context->bypass(fn (): array => CardEnrollment::query()->whereIn('card_id', $ids)->distinct()->pluck('card_id')->map(intval(...))->all())
            : [];
        $businesses = $managesProgram
            ? $this->context->bypass(fn (): array => LoyaltyCard::query()->whereKey($ids)->with(['businesses' => fn ($query) => $query->orderBy('name')])->get()
                ->mapWithKeys(fn (LoyaltyCard $card): array => [$card->id => $card->businesses->pluck('name')->all()])->all())
            : [];

        $forNewCustomers = null;

        if ($businessId !== null) {
            $honoured = $this->context->bypass(fn () => LoyaltyCard::query()->honouredBy($businessId)->get(['id', 'active']));
            $forNewCustomers = CardChoice::pick($honoured->modelKeys(), [], $honoured->where('active', true)->modelKeys());
        }

        return array_values($cards->map(fn (LoyaltyCard $card): array => [
            'id' => $card->id,
            'name' => $card->name,
            'mode' => $card->mode->value,
            'modeLabel' => $card->mode->getLabel(),
            'rewardText' => $card->reward_text,
            'stampsRequired' => $card->stamps_required,
            'active' => $card->active,
            'brandColor' => $card->brandColor(),
            'held' => $managesProgram ? in_array($card->id, $held, true) : null,
            'businesses' => $managesProgram ? array_values($businesses[$card->id] ?? []) : null,
            'forNewCustomers' => $card->id === $forNewCustomers,
        ])->all());
    }
}
