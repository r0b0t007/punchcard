<?php

declare(strict_types=1);

namespace App\Support\Cards;

/**
 * Which of a business's cards a customer gets there (CHW-25, CHW-148): a
 * running card they already hold, so a tap never splits their progress;
 * else the card the business runs now, even if they hold a paused one (its
 * stamps stay on it); else, with no card running, the one they hold or the
 * first (a tap is then refused as a paused card). Cards are taken in
 * LoyaltyCard::honouredBy order (running first, then oldest). EnrollCustomer
 * enrols on it; the join page shows it.
 */
final class CardChoice
{
    /**
     * @param  array<array-key, mixed>  $honoured  the business's card ids, in honouredBy order
     * @param  array<array-key, mixed>  $held  the ids of those the customer holds
     * @param  array<array-key, mixed>  $active  the ids of those running (not paused)
     */
    public static function pick(array $honoured, array $held, array $active): ?int
    {
        $honoured = array_values(array_map(intval(...), $honoured));
        $held = array_map(intval(...), $held);
        $active = array_map(intval(...), $active);

        $first = fn (callable $matches): ?int => array_values(array_filter($honoured, $matches))[0] ?? null;

        return $first(fn (int $card): bool => in_array($card, $held, true) && in_array($card, $active, true))
            ?? $first(fn (int $card): bool => in_array($card, $active, true))
            ?? $first(fn (int $card): bool => in_array($card, $held, true))
            ?? $honoured[0] ?? null;
    }
}
