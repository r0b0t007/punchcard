import type { MyCard } from '@/types/my-cards';

/**
 * C5 My cards, "next reward first" (Claude Design `CH MyCards v2`, review
 * 1a): the running card with the fewest stamps left to its next reward is
 * shown in full, ties going to the most recent visit; every other card keeps
 * its place in the list (the most recently used first). A paused card, or a
 * progressive card with every tier earned, is never the closest.
 */
export function closestToReward(cards: MyCard[]): {
    closest: MyCard | null;
    others: MyCard[];
} {
    let closest: MyCard | null = null;

    for (const myCard of cards) {
        if (
            !myCard.paused &&
            stampsLeft(myCard) !== null &&
            (closest === null || isCloser(myCard, closest))
        ) {
            closest = myCard;
        }
    }

    return {
        closest,
        others: cards.filter((myCard) => myCard !== closest),
    };
}

function isCloser(a: MyCard, b: MyCard): boolean {
    const left = (stampsLeft(a) ?? 0) - (stampsLeft(b) ?? 0);

    if (left !== 0) {
        return left < 0;
    }

    // Never visited counts as the oldest visit.
    return (
        (a.lastVisitDaysAgo ?? Number.POSITIVE_INFINITY) <
        (b.lastVisitDaysAgo ?? Number.POSITIVE_INFINITY)
    );
}

/** The server's count; a copy kept offline before it was sent counts down to the card's slots. */
function stampsLeft(myCard: MyCard): number | null {
    if (myCard.stampsToReward !== undefined) {
        return myCard.stampsToReward;
    }

    return Math.max(
        myCard.card.stampsRequired - myCard.card.stampsCollected,
        0,
    );
}
