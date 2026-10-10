<?php

declare(strict_types=1);

namespace App\Support\Cards;

use Illuminate\Support\Arr;

/**
 * Which of a business's cards a customer gets there (CHW-25, CHW-148): a
 * running card they already hold, the one they used last if they hold
 * several, so a tap never splits their progress (moved to a new card while
 * the old one was paused, they stay on it when the old one runs again); else
 * the card the business runs now, even if they hold a paused one (its stamps
 * stay on it); else, with no card running, the one they hold or the first (a
 * tap is then refused as a paused card). Cards they don't hold are taken in
 * LoyaltyCard::honouredBy order (running first, then oldest). EnrollCustomer
 * enrols on it; the join page shows it.
 */
final class CardChoice
{
    /**
     * @param  array<array-key, mixed>  $honoured  the business's card ids, in honouredBy order
     * @param  array<array-key, mixed>  $held  the ids of those the customer holds, the one used last first (CardEnrollment::lastUsedFirst)
     * @param  array<array-key, mixed>  $active  the ids of those running (not paused)
     */
    public static function pick(array $honoured, array $held, array $active): ?int
    {
        $honoured = array_values(array_map(intval(...), $honoured));
        $held = array_values(array_intersect(array_map(intval(...), $held), $honoured));
        $active = array_map(intval(...), $active);
        $running = fn (int $card): bool => in_array($card, $active, true);

        return Arr::first($held, $running)
            ?? Arr::first($honoured, $running)
            ?? $held[0]
            ?? $honoured[0]
            ?? null;
    }
}
