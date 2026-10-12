import { describe, expect, it } from 'vite-plus/test';
import { snapshotOf } from '@/lib/offline-cards';
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
    it('keeps whose cards, when, and the words to show them in', () => {
        expect(
            snapshotOf(
                {
                    firstName: 'Salma',
                    rewardsReady: 1,
                    cards: [card],
                    userId: 42,
                    locale: 'ar',
                    dir: 'rtl',
                    translations: { 'My cards': 'بطاقاتي' },
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
            translations: { 'My cards': 'بطاقاتي' },
        });
    });
});
