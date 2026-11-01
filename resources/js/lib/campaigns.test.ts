import { describe, expect, it } from 'vite-plus/test';
import {
    characters,
    newIdempotencyKey,
    notificationTitle,
    titleRoom,
} from '@/lib/campaigns';

describe('a campaign title beside the business name (CampaignText)', () => {
    it('shares 40 characters, the title keeping at least 15', () => {
        expect(titleRoom('A1')).toBe(35);
        expect(titleRoom('n'.repeat(30))).toBe(15);
        // Characters, as mb_strlen: an Arabic name counts its letters.
        expect(titleRoom('الحافة')).toBe(31);
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

describe('the composer idempotency key', () => {
    it('is a version 4 UUID, new each time', () => {
        const key = newIdempotencyKey();

        expect(key).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
        );
        expect(newIdempotencyKey()).not.toBe(key);
    });
});
