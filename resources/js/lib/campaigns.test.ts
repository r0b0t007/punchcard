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

    it('suggests the next full hour, tomorrow at midnight after 23:00', () => {
        expect(nextHour('2026-11-04', '14:02')).toEqual({
            date: '2026-11-04',
            time: '15:00',
        });
        expect(nextHour('2026-11-04', '09:59')).toEqual({
            date: '2026-11-04',
            time: '10:00',
        });
        expect(nextHour('2026-12-31', '23:40')).toEqual({
            date: '2027-01-01',
            time: '00:00',
        });
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
