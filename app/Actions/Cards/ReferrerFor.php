<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Referrals\CanonicalEmail;

/**
 * Whose invitation a new customer's card comes from (CHW-47): the
 * enrollment the referral code belongs to, when it counts, else none. It
 * counts on a card with referrals on, from another customer still here, for
 * someone new to the organization: their first card of its program, never
 * their own code, nor an alias of the referrer's address (CanonicalEmail).
 * No device is looked at (decided: a fingerprint is personal data to
 * declare, and easily dodged).
 *
 * Called by EnrollCustomer inside the transaction that makes the card, in
 * bypass(): the friend's account is locked first (a tap's order: the tap,
 * then the account, then cards), so two cards of the organization made at
 * once never both count as their first. The card is read here, whatever the
 * caller loaded of it. The join page asks first, nothing locked (wouldCount),
 * to say "A friend invited you" only when it would count.
 */
final readonly class ReferrerFor
{
    public function handle(int $cardId, User $friend, ?string $code): ?CardEnrollment
    {
        return $this->find($cardId, $friend, $code, lock: true);
    }

    /** Whether the code would count for this customer, or for anyone new while signed out. */
    public function wouldCount(int $cardId, ?User $friend, ?string $code): bool
    {
        return $this->find($cardId, $friend, $code, lock: false) instanceof CardEnrollment;
    }

    private function find(int $cardId, ?User $friend, ?string $code, bool $lock): ?CardEnrollment
    {
        $card = $code === null ? null : LoyaltyCard::query()->whereKey($cardId)->first(['id', 'organization_id', 'referrals_enabled']);

        if (! $card instanceof LoyaltyCard || ! $card->referrals_enabled) {
            return null;
        }

        $referrer = CardEnrollment::query()->where('card_id', $card->id)->where('referral_code', $code)->with('user:id,email,anonymised_at')->first();

        if (! $referrer instanceof CardEnrollment || User::isGone((int) $referrer->user_id)) {
            return null;
        }

        if (! $friend instanceof User) {
            return $referrer;
        }

        if ($referrer->user_id === $friend->id || CanonicalEmail::of((string) $referrer->user?->email) === CanonicalEmail::of($friend->email)) {
            return null;
        }

        if ($lock) {
            User::query()->whereKey($friend->id)->lockForUpdate()->first(['id']);
        }

        $knownHere = CardEnrollment::query()->where('organization_id', $card->organization_id)->where('user_id', $friend->id)->exists();

        return $knownHere ? null : $referrer;
    }
}
