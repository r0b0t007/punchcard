import { describe, expect, it } from 'vite-plus/test';
import {
    isOwnedBy,
    keptForSomeoneElse,
    snapshotOf,
    updatedAtLabel,
} from '@/lib/offline-cards';
import type { MyCard } from '@/types/my-cards';

const card: MyCard = {
    id: 7,
    // Its look is kept as it is.
    card: { cardName: 'Hafa card' } as unknown as MyCard['card'],
    logoUrl: null,
    rewardsReady: 1,
    lastVisitDaysAgo: 2,
    paused: false,
};

describe('snapshotOf', () => {
    it('keeps whose cards, when, and in which language', () => {
        expect(
            snapshotOf(
                {
                    userId: 42,
                    firstName: 'Salma',
                    rewardsReady: 1,
                    cards: [card],
                    locale: 'ar',
                    dir: 'rtl',
                },
                new Date('2026-10-11T11:00:00Z'),
            ),
        ).toEqual({
            userId: 42,
            firstName: 'Salma',
            rewardsReady: 1,
            cards: [card],
            generatedAt: '2026-10-11T11:00:00.000Z',
            locale: 'ar',
            dir: 'rtl',
        });
    });
});

describe('keptForSomeoneElse', () => {
    it('keeps the cards of whoever is signed in', () => {
        expect(keptForSomeoneElse('42', 42)).toBe(false);
    });

    it('drops them for anyone else, or a guest', () => {
        expect(keptForSomeoneElse('42', 7)).toBe(true);
        expect(keptForSomeoneElse('42', null)).toBe(true);
    });

    it('has nothing to drop when nothing is kept', () => {
        expect(keptForSomeoneElse(null, 42)).toBe(false);
        expect(keptForSomeoneElse(null, null)).toBe(false);
    });
});

describe('isOwnedBy', () => {
    const snapshot = snapshotOf(
        {
            userId: 42,
            firstName: 'Salma',
            rewardsReady: 0,
            cards: [],
            locale: 'en',
            dir: 'ltr',
        },
        new Date('2026-10-11T11:00:00Z'),
    );

    it('shows the cards of the last signed-in customer', () => {
        expect(isOwnedBy(snapshot, '42')).toBe(true);
    });

    it("never shows anyone else's, nor a copy whose owner is unknown", () => {
        expect(isOwnedBy(snapshot, '7')).toBe(false);
        expect(isOwnedBy(snapshot, null)).toBe(false);
    });
});

describe('updatedAtLabel', () => {
    it("says when, in the customer's language, with Latin digits", () => {
        expect(updatedAtLabel('2026-10-11T11:00:00Z', 'en', 'UTC')).toContain(
            '2026',
        );
        expect(updatedAtLabel('2026-10-11T11:00:00Z', 'ar', 'UTC')).toContain(
            '2026',
        );
        expect(updatedAtLabel('2026-10-11T11:00:00Z', 'ar', 'UTC')).toMatch(
            /11:00/,
        );
    });
});
