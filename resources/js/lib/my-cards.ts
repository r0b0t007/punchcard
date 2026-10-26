import type { MyCard } from '@/types/my-cards';

/**
 * C5 My cards, "next reward first" (Claude Design `CH MyCards v2`, review
 * 1a): the running card with the fewest stamps left is shown in full, ties
 * going to the most recent visit; every other card keeps its place in the
 * list (the most recently used first). A paused card is never the closest.
 */
export function closestToReward(cards: MyCard[]): {
    closest: MyCard | null;
    others: MyCard[];
} {
    let closest: MyCard | null = null;

    for (const myCard of cards) {
        if (!myCard.paused && (closest === null || isCloser(myCard, closest))) {
            closest = myCard;
        }
    }

    return {
        closest,
        others: cards.filter((myCard) => myCard !== closest),
    };
}

function isCloser(a: MyCard, b: MyCard): boolean {
    const left = stampsLeft(a) - stampsLeft(b);

    if (left !== 0) {
        return left < 0;
    }

    // Never visited counts as the oldest visit.
    return (
        (a.lastVisitDaysAgo ?? Number.POSITIVE_INFINITY) <
        (b.lastVisitDaysAgo ?? Number.POSITIVE_INFINITY)
    );
}

function stampsLeft(myCard: MyCard): number {
    return Math.max(
        myCard.card.stampsRequired - myCard.card.stampsCollected,
        0,
    );
}
