<?php

declare(strict_types=1);

namespace App\Support\Cards;

use LogicException;

/**
 * A progressive card's tiers: a non-empty list of {stamps: int >= 1, reward:
 * non-blank string}, each stamps count once. Checked when the card is saved
 * (LoyaltyCard's write guard) and again when stamps are counted (AddStamps),
 * so a misconfigured card never silently drops a reward.
 */
final class ProgressiveTiers
{
    /**
     * The tiers, lowest first; throws when they are malformed. Takes the cast
     * array or the stored JSON.
     *
     * @return list<array{stamps: int, reward: string}>
     */
    public static function parse(mixed $tiers): array
    {
        if (is_string($tiers)) {
            $tiers = json_decode($tiers, true);
        }

        if (! is_array($tiers) || $tiers === []) {
            throw new LogicException('A progressive card needs tiers.');
        }

        $valid = [];

        foreach ($tiers as $tier) {
            $malformed = ! is_array($tier)
                || ! is_int($tier['stamps'] ?? null)
                || $tier['stamps'] < 1
                || isset($valid[$tier['stamps']])
                || ! is_string($tier['reward'] ?? null)
                || trim($tier['reward']) === '';

            if ($malformed) {
                throw new LogicException('Malformed tiers: each needs its own stamps count (1 or more) and a reward.');
            }

            $valid[$tier['stamps']] = ['stamps' => $tier['stamps'], 'reward' => $tier['reward']];
        }

        ksort($valid);

        return array_values($valid);
    }
}
