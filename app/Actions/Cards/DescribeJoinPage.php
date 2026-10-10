<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Cards\CardChoice;
use App\Support\Cards\CustomerCard;
use App\Support\Tenancy\TenantContext;

/**
 * What a join page shows (CHW-31): the card a tap or joining would use
 * there (CardChoice: a running card the signed-in customer holds, else the
 * card the business runs now), with their stamps. With no card running,
 * nothing is available to join yet, as a tap would be refused. Read across
 * tenants.
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
            // The card a tap or joining would use (CardChoice): until it is active, nothing is available.
            $honoured = LoyaltyCard::query()->honouredBy($business->id)->get()->keyBy('id');
            $held = $customer instanceof User
                ? CardEnrollment::query()->whereIn('card_id', $honoured->keys())->where('user_id', $customer->id)->get()->keyBy('card_id')
                : collect();
            $card = $honoured->get(CardChoice::pick($honoured->keys()->all(), $held->keys()->all(), $honoured->where('active', true)->keys()->all()));
            $enrollment = $card instanceof LoyaltyCard ? $held->get($card->id) : null;

            if (! $card instanceof LoyaltyCard || ! $card->active) {
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
