import { describe, expect, it } from 'vite-plus/test';
import { contrastRatio, readableForeground, stampColor } from '@/lib/color';

describe('contrastRatio', () => {
    it('matches the WCAG formula', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 5);
        expect(contrastRatio('#ffffff', '#ffffff')).toBeCloseTo(1, 5);
        expect(contrastRatio('#241A13', '#FBF6EE')).toBeCloseTo(15.84, 1);
    });

    it('accepts 3-digit hex', () => {
        expect(contrastRatio('#fff', '#000')).toBeCloseTo(21, 5);
    });
});

describe('readableForeground', () => {
    it('picks cream on dark brand colours and ink on light ones', () => {
        expect(readableForeground('#3B2A20')).toBe('#FBF6EE');
        expect(readableForeground('#0F4C81')).toBe('#FBF6EE');
        expect(readableForeground('#F2A541')).toBe('#241A13');
        expect(readableForeground('#FFE8D6')).toBe('#241A13');
    });

    it('always reaches at least 4.5:1', () => {
        for (const brand of [
            '#E11D48',
            '#16A34A',
            '#7C3AED',
            '#0EA5E9',
            '#808080',
            '#B45309',
        ]) {
            expect(
                contrastRatio(readableForeground(brand), brand),
            ).toBeGreaterThanOrEqual(4.5);
        }
    });

    it('falls back to the default espresso card for invalid input', () => {
        expect(readableForeground('not-a-colour')).toBe('#FBF6EE');
    });
});

describe('stampColor', () => {
    it('uses saffron when it stands out from the card (3:1)', () => {
        expect(stampColor('#3B2A20')).toBe('#F2A541');
        expect(stampColor('#0F4C81')).toBe('#F2A541');
    });

    it('falls back to the readable foreground on light or saffron-like cards', () => {
        expect(stampColor('#F2A541')).toBe('#241A13');
        expect(stampColor('#FFFFFF')).toBe('#241A13');
    });
});

describe('stampColor with a stored foreground', () => {
    it('falls back to the card text colour the server stored', () => {
        expect(stampColor('#F2A541', '#FFFFFF')).toBe('#FFFFFF');
        expect(stampColor('#3B2A20', '#FFFFFF')).toBe('#F2A541');
    });
});
