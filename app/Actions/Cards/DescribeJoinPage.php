<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Cards\CustomerCard;
use App\Support\Tenancy\TenantContext;

/**
 * What a join page shows (CHW-31): the card the signed-in customer already
 * holds there, with their stamps, as a tap would use it (EnrollCustomer);
 * otherwise the business's active card, the one joining gives. With
 * no active card, nothing is available to join yet. Read across tenants.
 */
final readonly class DescribeJoinPage
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{card: ?array<string, mixed>, available: bool, joined: bool}
     */
    public function handle(Business $business, ?User $customer): array
    {
        return $this->context->bypass(function () use ($business, $customer): array {
            // A card the customer holds here first, in the order EnrollCustomer and a tap choose it.
            $honoured = LoyaltyCard::query()->honouredBy($business->id)->get();
            $held = $customer instanceof User
                ? CardEnrollment::query()->whereIn('card_id', $honoured->modelKeys())->where('user_id', $customer->id)->get()->keyBy('card_id')
                : collect();
            $card = $honoured->first(fn (LoyaltyCard $card): bool => $held->has($card->id)) ?? $honoured->firstWhere('active', true);
            $enrollment = $card instanceof LoyaltyCard ? $held->get($card->id) : null;

            if (! $card instanceof LoyaltyCard) {
                return ['card' => null, 'available' => false, 'joined' => false];
            }

            return [
                'card' => CustomerCard::of($business, $card, stamps: min($enrollment->current_stamps ?? 0, $card->stamps_required)),
                'available' => true,
                'joined' => $enrollment instanceof CardEnrollment,
            ];
        });
    }
}
