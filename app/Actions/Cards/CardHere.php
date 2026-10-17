<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use Illuminate\Support\Collection;

/** The card a customer collects on at a business, and what they hold there (ChooseCard). */
final readonly class CardHere
{
    /**
     * @param  LoyaltyCard|null  $card  the card chosen (CardChoice), null when the business honours none
     * @param  CardEnrollment|null  $enrollment  theirs on it, null when they would be new on it
     * @param  Collection<int, CardEnrollment>  $held  their enrollments on the cards the business honours, by card id, the one used last first
     */
    public function __construct(
        public ?LoyaltyCard $card,
        public ?CardEnrollment $enrollment,
        public Collection $held,
    ) {}
}
