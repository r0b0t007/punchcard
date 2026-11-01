import { describe, expect, it } from 'vite-plus/test';
import {
    addDays,
    characters,
    clip,
    newIdempotencyKey,
    nextHour,
    notificationTitle,
} from '@/lib/campaigns';

describe('a campaign title beside the business name (CampaignText)', () => {
    it('counts and cuts in characters, as mb_strlen: an emoji or an Arabic letter is one', () => {
        expect(characters('الحافة')).toBe(6);
        expect(characters('☕🎉')).toBe(2);
        expect(clip('🎉🎉🎉', 2)).toBe('🎉🎉');
        expect(clip('Slow', 10)).toBe('Slow');
    });

    it('titles the notification with the name, cut to fit a long one', () => {
        expect(notificationTitle('Café Hafa', 'Slow Tuesday?')).toBe(
            'Café Hafa · Slow Tuesday?',
        );

        const cut = notificationTitle('n'.repeat(30), 't'.repeat(15));
        expect(cut).toBe(`${'n'.repeat(21)}… · ${'t'.repeat(15)}`);
        expect(characters(cut)).toBe(40);
    });
});

describe("a later campaign's day and time", () => {
    it('counts days by the calendar, across months', () => {
        expect(addDays('2026-11-04', 30)).toBe('2026-12-04');
        expect(addDays('2026-12-31', 1)).toBe('2027-01-01');
    });

    it('suggests the next full hour, never past the day', () => {
        expect(nextHour('14:02')).toBe('15:00');
        expect(nextHour('09:59')).toBe('10:00');
        expect(nextHour('23:40')).toBe('23:00');
    });
});

describe('the composer idempotency key', () => {
    it('is a version 4 UUID, new each time', () => {
        const key = newIdempotencyKey();

        expect(key).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
        );
        expect(newIdempotencyKey()).not.toBe(key);
    });
});
