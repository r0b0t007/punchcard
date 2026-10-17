<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Support\Cards\CardChoice;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Which card a customer collects on at a business (CardChoice: a running
 * card they hold, the one used last; else the card it runs now), with their
 * enrollment on it and the others they hold there. The one choice that
 * EnrollCustomer enrols on and the scan result (DescribeScan, CHW-30) shows,
 * so staff see the card the stamp lands on. Enrols nobody. In bypass(): a
 * customer's cards span the organization.
 */
final readonly class ChooseCard
{
    public function __construct(private TenantContext $context) {}

    public function handle(int $businessId, int $userId): CardHere
    {
        return $this->context->bypass(function () use ($businessId, $userId): CardHere {
            // Only what the callers read: EnrollCustomer runs this on every tap.
            $honoured = LoyaltyCard::query()->honouredBy($businessId)->get(['id', 'active', 'name', 'stamps_required', 'reward_text']);
            /** @var Collection<int, CardEnrollment> $held */
            $held = CardEnrollment::query()->whereIn('card_id', $honoured->modelKeys())->where('user_id', $userId)->lastUsedFirst()->get()->keyBy('card_id');
            $cardId = CardChoice::pick($honoured->modelKeys(), $held->keys()->all(), $honoured->where('active', true)->modelKeys());

            return new CardHere(
                $cardId === null ? null : $honoured->firstWhere('id', $cardId),
                $cardId === null ? null : $held->get($cardId),
                $held,
            );
        });
    }
}
