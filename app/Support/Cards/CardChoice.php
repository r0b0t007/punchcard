<?php

declare(strict_types=1);

namespace App\Support\Cards;

/**
 * Which of a business's cards a customer gets there (CHW-25, CHW-31): the
 * first card they already hold, in LoyaltyCard::honouredBy order (active
 * first, then oldest), so a tap never splits their progress; otherwise the
 * first card honoured. EnrollCustomer enrols on it; the join page shows it.
 */
final class CardChoice
{
    /**
     * @param  array<array-key, mixed>  $honoured  the business's card ids, in honouredBy order
     * @param  array<array-key, mixed>  $held  the ids of those the customer holds
     */
    public static function pick(array $honoured, array $held): ?int
    {
        $honoured = array_values(array_map(intval(...), $honoured));
        $held = array_map(intval(...), $held);

        foreach ($honoured as $cardId) {
            if (in_array($cardId, $held, true)) {
                return $cardId;
            }
        }

        return $honoured[0] ?? null;
    }
}
