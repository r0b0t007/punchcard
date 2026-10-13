import { describe, expect, it } from 'vite-plus/test';
import {
    cardsAsOf,
    isOwnedBy,
    keptForSomeoneElse,
    phoneLocale,
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

describe('cardsAsOf', () => {
    const never: MyCard = { ...card, id: 8, lastVisitDaysAgo: null };

    it('counts the days since the cards were saved, on the phone’s calendar', () => {
        // Saved late on the 11th, shown early on the 14th: three days on.
        const shown = cardsAsOf(
            [card, never],
            new Date(2026, 9, 11, 23, 30).toISOString(),
            new Date(2026, 9, 14, 0, 30),
        );

        expect(shown.map((kept) => kept.lastVisitDaysAgo)).toEqual([5, null]);
    });

    it('changes nothing on the day they were saved', () => {
        expect(
            cardsAsOf(
                [card],
                new Date(2026, 9, 11, 8, 0).toISOString(),
                new Date(2026, 9, 11, 22, 0),
            )[0].lastVisitDaysAgo,
        ).toBe(2);
    });
});

describe('phoneLocale', () => {
    const supported = ['fr', 'en', 'ar'];

    it('picks the first of the phone’s languages the app speaks', () => {
        expect(phoneLocale(['ar-MA', 'fr-FR'], supported, 'fr')).toBe('ar');
        expect(phoneLocale(['de-DE', 'en-GB', 'fr'], supported, 'fr')).toBe(
            'en',
        );
    });

    it("falls back to the app's default language", () => {
        expect(phoneLocale(['de-DE'], supported, 'fr')).toBe('fr');
        expect(phoneLocale([], supported, 'fr')).toBe('fr');
    });
});
