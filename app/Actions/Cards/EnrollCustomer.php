<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Cards\CardChoice;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Finds or creates a customer's card at a business (CHW-25): the first tap at
 * a café enrolls the customer on its card. Which card is CardChoice's: a
 * running card the customer holds there (their progress never splits), else
 * the card the business runs now, even if they hold a paused one (CHW-148;
 * its stamps stay on it); null when it honours none. With no card running,
 * a switched-off one is still returned, so AddStamps refuses it as inactive
 * (ApplyTap's savepoint drops a new enrollment). A new enrollment gets an unguessable
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
            $honoured = LoyaltyCard::query()->honouredBy($business->id)->get(['id', 'active']);
            $cards = $honoured->modelKeys();

            if ($cards === []) {
                return null;
            }

            // A running card the customer holds here comes first, so a tap never splits their progress;
            // one they hold that is paused gives way to the card the business runs now (CHW-148).
            $held = CardEnrollment::query()->whereIn('card_id', $cards)->where('user_id', $user->id)->get()->keyBy('card_id');
            $card = (int) CardChoice::pick($cards, $held->keys()->all(), $honoured->where('active', true)->modelKeys());

            if ($held->has($card)) {
                return $held->get($card);
            }

            for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
                $existing = CardEnrollment::query()->where('card_id', $card)->where('user_id', $user->id)->first();

                if ($existing instanceof CardEnrollment) {
                    return $existing;
                }

                try {
                    return DB::transaction(function () use ($card, $user): CardEnrollment {
                        $enrollment = (new CardEnrollment)->forceFill(['card_id' => $card, 'user_id' => $user->id, 'referral_code' => $this->referralCode()]);
                        $enrollment->save();

                        return $enrollment;
                    });
                } catch (UniqueConstraintViolationException) {
                    // A racing first tap won (the next attempt finds its enrollment), or the code was taken.
                }
            }

            throw new RuntimeException("Could not enroll user {$user->id} on card {$card}.");
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
