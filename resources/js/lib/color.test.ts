// @ts-expect-error -- Node's fs: the browser code loads no Node types, and a test shouldn't add them for every file.
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vite-plus/test';
import {
    BRAND_SWATCHES,
    brandMarkOn,
    cardInks,
    contrastRatio,
    isWeakBrand,
    readableForeground,
    stampColor,
    SURFACE,
} from '@/lib/color';

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

describe('SURFACE', () => {
    it("is app.css's --card, light and dark", () => {
        const appCss = readFileSync(
            new URL('../../css/app.css', import.meta.url),
            'utf8',
        );
        const cards = [
            ...appCss.matchAll(/^\s*--card:\s*(#[0-9a-f]{6});/gim),
        ].map((match) => match[1].toUpperCase());

        expect(cards).toEqual([SURFACE.light, SURFACE.dark]);
    });
});

describe('brandMarkOn', () => {
    it('keeps the brand colour where it stands out from the surface (3:1)', () => {
        expect(brandMarkOn(SURFACE.light, '#3B2A20', '#FBF6EE')).toBe(
            '#3B2A20',
        );
        expect(brandMarkOn(SURFACE.dark, '#CFE8D8', '#241A13')).toBe('#CFE8D8');
    });

    it("turns to the card's text colour where it would blend in", () => {
        expect(brandMarkOn(SURFACE.dark, '#3B2A20', '#FBF6EE')).toBe('#FBF6EE');
        expect(brandMarkOn(SURFACE.light, '#F2A541', '#241A13')).toBe(
            '#241A13',
        );
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

describe('cardInks', () => {
    it('uses a stored foreground only while it still reads on the brand colour', () => {
        expect(cardInks('#0F4C81', '#FBF6EE')).toEqual({
            brand: '#0F4C81',
            foreground: '#FBF6EE',
            stamp: '#F2A541',
        });
        // stale: computed for another colour, cream on saffron is ~1.9:1
        expect(cardInks('#F2A541', '#FBF6EE')).toEqual({
            brand: '#F2A541',
            foreground: '#241A13',
            stamp: '#241A13',
        });
    });

    it('ignores a stored foreground when the brand colour is invalid', () => {
        expect(cardInks('rgb(255,230,200)', '#241A13')).toEqual({
            brand: '#3B2A20',
            foreground: '#FBF6EE',
            stamp: '#F2A541',
        });
        expect(cardInks(null, null)).toEqual({
            brand: '#3B2A20',
            foreground: '#FBF6EE',
            stamp: '#F2A541',
        });
    });
});

describe('isWeakBrand', () => {
    it('matches Contrast::isWeak on the server', () => {
        expect(isWeakBrand('#808080')).toBe(true);
        expect(isWeakBrand('#3B2A20')).toBe(false);
        expect(isWeakBrand('#FFE8D6')).toBe(false);
    });

    it('never flags a builder swatch', () => {
        for (const swatch of BRAND_SWATCHES) {
            expect(isWeakBrand(swatch.hex)).toBe(false);
        }
    });
});
