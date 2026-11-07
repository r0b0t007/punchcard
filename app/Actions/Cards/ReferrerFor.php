<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\User;

/**
 * Whose invitation a new customer's card comes from (CHW-47): the
 * enrollment the referral code belongs to, when it counts, else none. It
 * counts on a card with referrals on, from another customer still here, for
 * someone new to the organization: their first card of its program, never
 * their own code. No device is looked at (decided: a fingerprint is personal
 * data to declare, and easily dodged). Read in bypass(), by EnrollCustomer,
 * before it adds the card: the card is read here, whatever the caller
 * loaded of it.
 */
final readonly class ReferrerFor
{
    public function handle(int $cardId, User $friend, ?string $code): ?CardEnrollment
    {
        $card = $code === null ? null : LoyaltyCard::query()->whereKey($cardId)->first(['id', 'organization_id', 'referrals_enabled']);

        if (! $card instanceof LoyaltyCard || ! $card->referrals_enabled) {
            return null;
        }

        $referrer = CardEnrollment::query()->where('card_id', $card->id)->where('referral_code', $code)->first();

        if (! $referrer instanceof CardEnrollment || $referrer->user_id === $friend->id || User::isGone((int) $referrer->user_id)) {
            return null;
        }

        $knownHere = CardEnrollment::query()->where('organization_id', $card->organization_id)->where('user_id', $friend->id)->exists();

        return $knownHere ? null : $referrer;
    }
}
