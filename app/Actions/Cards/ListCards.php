<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Support\Cards\CardChoice;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * The cards page (CHW-32, B7). For whoever runs the program (the org admin),
 * every card of the organization, with whether customers hold it, the
 * operating businesses honouring it and those where it is the only card
 * running (pausing it stops taps there); for a franchisee's owner, the cards
 * its business honours, read only, and nothing about who holds them. Working
 * in a business, the running card new customers get there is marked
 * (CardChoice with nothing held). A card without its own colour shows its
 * organization's (ADR 0007). Running cards first, then the oldest.
 */
final readonly class ListCards
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return list<array{id: int, name: string, mode: string, modeLabel: string, rewardText: string, stampsRequired: int, active: bool, brandColor: ?string, brandForeground: ?string, held: ?bool, businesses: ?list<string>, onlyRunningAt: ?list<string>, forNewCustomers: bool}>
     */
    public function handle(bool $managesProgram): array
    {
        $businessId = $this->context->businessId();

        if (! $managesProgram && $businessId === null) {
            return [];
        }

        $cards = $managesProgram
            ? LoyaltyCard::query()->orderByDesc('active')->orderBy('id')->get()
            : LoyaltyCard::query()->honouredBy($businessId)->get();
        $held = [];
        $honoured = $cards;

        if ($managesProgram) {
            $held = $this->context->bypass(fn (): array => CardEnrollment::query()->whereIn('card_id', $cards->modelKeys())->distinct()->pluck('card_id')->map(intval(...))->all());
            $this->context->bypass(fn (): Collection => $cards->load('operatingBusinesses'));
            $honoured = $cards->filter(fn (LoyaltyCard $card): bool => $card->operatingBusinesses->contains('id', $businessId));
        }

        $running = $cards->where('active', true);
        $runningHere = $honoured->where('active', true)->modelKeys();
        $chosen = $businessId === null ? null : CardChoice::pick($honoured->modelKeys(), [], $runningHere);
        // With no card running here, CardChoice still names one, which refuses taps.
        $forNewCustomers = in_array($chosen, $runningHere, true) ? $chosen : null;
        $organizationColor = $this->context->bypass(fn (): mixed => Organization::query()->whereKey($this->context->organizationId())->value('brand_color'));

        return array_values($cards->map(fn (LoyaltyCard $card): array => [
            'id' => $card->id,
            'name' => $card->name,
            'mode' => $card->mode->value,
            'modeLabel' => $card->mode->getLabel(),
            'rewardText' => $card->reward_text,
            'stampsRequired' => $card->stamps_required,
            'active' => $card->active,
            'brandColor' => $card->brandColor() ?? (is_string($organizationColor) ? $organizationColor : null),
            'brandForeground' => $card->brandForeground(),
            'held' => $managesProgram ? in_array($card->id, $held, true) : null,
            'businesses' => $managesProgram ? array_values($card->operatingBusinesses->pluck('name')->all()) : null,
            'onlyRunningAt' => $managesProgram && $card->active
                ? array_values($card->operatingBusinesses
                    ->reject(fn (Business $business): bool => $running->contains(fn (LoyaltyCard $other): bool => $other->isNot($card) && $other->operatingBusinesses->contains($business)))
                    ->pluck('name')->all())
                : null,
            'forNewCustomers' => $card->id === $forNewCustomers,
        ])->all());
    }
}
