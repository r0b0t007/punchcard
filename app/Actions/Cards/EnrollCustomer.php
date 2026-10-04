<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Finds or creates a customer's card at a business (CHW-25): the first tap at
 * a café enrolls the customer on its card. The card is the business's
 * honoured card, an active one first, the oldest if it has several (one card
 * per business for the MVP); null when it honours none. A switched-off card
 * is still returned, so AddStamps refuses it as inactive (ApplyTap's
 * savepoint drops the new enrollment). A new enrollment gets an unguessable
 * referral code. Two first taps racing for the same card meet on
 * unique(card_id, user_id), and the loser takes the winner's enrollment. Runs
 * in bypass(): the customer has no tenant.
 */
final readonly class EnrollCustomer
{
    /** Referral code characters: no 0/O or 1/I to misread. */
    private const string CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const int CODE_LENGTH = 8;

    private const int ATTEMPTS = 3;

    public function __construct(private TenantContext $context) {}

    public function handle(Business $business, User $user): ?CardEnrollment
    {
        return $this->context->bypass(function () use ($business, $user): ?CardEnrollment {
            $card = LoyaltyCard::query()
                ->whereHas('businesses', fn ($businesses) => $businesses->whereKey($business->id))
                ->orderByDesc('active')
                ->orderBy('id')
                ->first();

            if (! $card instanceof LoyaltyCard) {
                return null;
            }

            for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
                $existing = CardEnrollment::query()->where('card_id', $card->id)->where('user_id', $user->id)->first();

                if ($existing instanceof CardEnrollment) {
                    return $existing;
                }

                try {
                    return DB::transaction(function () use ($card, $user): CardEnrollment {
                        $enrollment = (new CardEnrollment)->forceFill(['card_id' => $card->id, 'user_id' => $user->id, 'referral_code' => $this->referralCode()]);
                        $enrollment->save();

                        return $enrollment;
                    });
                } catch (UniqueConstraintViolationException) {
                    // A racing first tap won (the next attempt finds its enrollment), or the code was taken.
                }
            }

            throw new RuntimeException("Could not enroll user {$user->id} on card {$card->id}.");
        });
    }

    private function referralCode(): string
    {
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return $code;
    }
}
