import { describe, expect, it } from 'vite-plus/test';
import { closestToReward } from '@/lib/my-cards';
import type { MyCard } from '@/types/my-cards';

function myCard(
    id: number,
    collected: number,
    required: number,
    facts: Partial<
        Pick<MyCard, 'lastVisitDaysAgo' | 'paused' | 'stampsToReward'>
    > = {},
): MyCard {
    return {
        id,
        card: {
            stampsCollected: collected,
            stampsRequired: required,
        } as MyCard['card'],
        logoUrl: null,
        rewardsReady: 0,
        lastVisitDaysAgo: 0,
        paused: false,
        ...facts,
    };
}

const ids = (cards: MyCard[]) => cards.map((c) => c.id);

describe('closestToReward', () => {
    it('picks the card with the fewest stamps left, the others keeping their order', () => {
        const { closest, others } = closestToReward([
            myCard(1, 1, 10),
            myCard(2, 8, 10),
            myCard(3, 3, 8),
        ]);

        expect(closest?.id).toBe(2);
        expect(ids(others)).toEqual([1, 3]);
    });

    it('counts stamps left, not stamps collected', () => {
        expect(
            closestToReward([myCard(1, 6, 10), myCard(2, 4, 5)]).closest?.id,
        ).toBe(2);
    });

    it('gives a tie to the most recent visit, a card never visited last', () => {
        expect(
            closestToReward([
                myCard(1, 3, 5, { lastVisitDaysAgo: null }),
                myCard(2, 3, 5, { lastVisitDaysAgo: 4 }),
                myCard(3, 3, 5, { lastVisitDaysAgo: 1 }),
            ]).closest?.id,
        ).toBe(3);
        expect(
            closestToReward([
                myCard(1, 3, 5, { lastVisitDaysAgo: 2 }),
                myCard(2, 3, 5, { lastVisitDaysAgo: 2 }),
            ]).closest?.id,
        ).toBe(1);
    });

    it("ranks by the server's stamps to the next reward, a progressive card's next tier", () => {
        // A progressive card at 4 of 10, one stamp from its first tier (5).
        expect(
            closestToReward([
                myCard(1, 7, 10, { stampsToReward: 3 }),
                myCard(2, 4, 10, { stampsToReward: 1 }),
            ]).closest?.id,
        ).toBe(2);
    });

    it('never puts a progressive card with every tier earned first', () => {
        const { closest, others } = closestToReward([
            myCard(1, 10, 10, { stampsToReward: null }),
            myCard(2, 1, 10, { stampsToReward: 9 }),
        ]);

        expect(closest?.id).toBe(2);
        expect(ids(others)).toEqual([1]);
        expect(
            closestToReward([myCard(1, 10, 10, { stampsToReward: null })])
                .closest,
        ).toBeNull();
    });

    it('never puts a paused card first', () => {
        const { closest, others } = closestToReward([
            myCard(1, 1, 10),
            myCard(2, 9, 10, { paused: true }),
        ]);

        expect(closest?.id).toBe(1);
        expect(ids(others)).toEqual([2]);
    });

    it('has no closest card when every card is paused, or there is none', () => {
        expect(closestToReward([myCard(1, 9, 10, { paused: true })])).toEqual({
            closest: null,
            others: [expect.objectContaining({ id: 1 })],
        });
        expect(closestToReward([])).toEqual({ closest: null, others: [] });
    });
});
