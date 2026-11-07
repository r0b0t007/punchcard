import { describe, expect, it } from 'vitest';
import { centimes, dirhams, formatDirhams } from '@/lib/money';

describe('centimes', () => {
    it('turns dirhams typed with a point or a comma into centimes', () => {
        expect(centimes('35')).toBe('3500');
        expect(centimes(' 35.5 ')).toBe('3550');
        expect(centimes('35,50')).toBe('3550');
    });

    it('leaves anything else as typed, for the server to refuse', () => {
        expect(centimes('35 DH')).toBe('35 DH');
        expect(centimes('35.555')).toBe('35.555');
        expect(centimes('')).toBe('');
    });
});

describe('dirhams', () => {
    it('shows centimes as dirhams to type again', () => {
        expect(dirhams(3500)).toBe('35');
        expect(dirhams(3550)).toBe('35.5');
    });
});

describe('formatDirhams', () => {
    it('shows whole dirhams without decimals, and others with two, in Latin digits', () => {
        expect(formatDirhams('en', 3500)).toMatch(/^MAD\s?35$/);
        expect(formatDirhams('fr', 3550)).toMatch(/35,50\s?MAD/);
        expect(formatDirhams('ar', 17500)).toContain('175');
    });
});
