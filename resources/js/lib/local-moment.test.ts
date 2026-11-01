import { describe, expect, it } from 'vite-plus/test';
import { calendarDate, momentLabel } from '@/lib/local-moment';

describe('calendarDate', () => {
    it('writes Arabic dates with Latin digits', () => {
        expect(calendarDate('ar', '2026-10-05', { day: 'numeric' })).toBe('5');
    });

    it('never shifts the day, whatever the timezone', () => {
        expect(
            calendarDate('en', '2026-10-05', { day: 'numeric', month: 'long' }),
        ).toBe('October 5');
    });
});

describe('momentLabel', () => {
    it('words today and another day', () => {
        const words = {
            today: (time: string) => `today ${time}`,
            other: (date: string, time: string) => `${date} ${time}`,
        };

        expect(
            momentLabel(
                'en',
                { day: 'today', date: '2026-10-05', time: '10:42' },
                words,
            ),
        ).toBe('today 10:42');
        expect(
            momentLabel(
                'en',
                { day: 'other', date: '2026-10-05', time: '10:42' },
                words,
            ),
        ).toBe('Monday, October 5 10:42');
        // Shorter where the caller asks (a list's "28 Oct").
        expect(
            momentLabel(
                'fr',
                { day: 'other', date: '2026-10-28', time: '16:00' },
                words,
                { day: 'numeric', month: 'short' },
            ),
        ).toBe('28 oct. 16:00');
    });
});
