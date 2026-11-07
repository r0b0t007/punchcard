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
 * tenants. Came by a friend's link (CHW-47, design 20c): invited when the
 * code would count for that card and this customer (ReferrerFor), never
 * saying who.
 */
final readonly class DescribeJoinPage
{
    public function __construct(private TenantContext $context, private ReferrerFor $referrerFor) {}

    /**
     * @return array{card: ?array<string, mixed>, available: bool, joined: bool, invited: bool}
     */
    public function handle(Business $business, ?User $customer, ?string $referralCode = null): array
    {
        return $this->context->bypass(function () use ($business, $customer, $referralCode): array {
            // The card a tap or joining would use (CardChoice): until it is active, nothing is available.
            $honoured = LoyaltyCard::query()->honouredBy($business->id)->get()->keyBy('id');
            $held = $customer instanceof User
                ? CardEnrollment::query()->whereIn('card_id', $honoured->keys())->where('user_id', $customer->id)->lastUsedFirst()->get()->keyBy('card_id')
                : collect();
            $card = $honoured->get(CardChoice::pick($honoured->keys()->all(), $held->keys()->all(), $honoured->where('active', true)->keys()->all()));
            $enrollment = $card instanceof LoyaltyCard ? $held->get($card->id) : null;

            if (! $card instanceof LoyaltyCard || ! $card->active) {
                return ['card' => null, 'available' => false, 'joined' => false, 'invited' => false];
            }

            return [
                'card' => CustomerCard::of($business, $card, stamps: $enrollment->current_stamps ?? 0),
                'available' => true,
                'joined' => $enrollment instanceof CardEnrollment,
                'invited' => ! $enrollment instanceof CardEnrollment && $this->referrerFor->wouldCount($card->id, $customer, $referralCode),
            ];
        });
    }
}
