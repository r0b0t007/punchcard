/**
 * WCAG colour helpers for organization brand colours (ADR 0007): a brand colour
 * may only paint the loyalty card, so the card picks its own readable ink.
 * The server should store the same foreground when the owner saves a colour;
 * this is the client-side fallback and the live preview.
 */

/** punchcard's own inks, also the default espresso card (design-tokens skill). */
export const CREAM = '#FBF6EE';
export const INK = '#241A13';
export const ESPRESSO = '#3B2A20';
export const SAFFRON = '#F2A541';

/** WCAG 2.x contrast ratio between two hex colours (#rgb or #rrggbb), 1 to 21. */
export function contrastRatio(a: string, b: string): number {
    const [lighter, darker] = [luminance(a), luminance(b)].sort(
        (x, y) => y - x,
    );

    return (lighter + 0.05) / (darker + 0.05);
}

/**
 * The text colour for a brand-coloured card, always >= 4.5:1: punchcard's cream
 * or ink when one of them passes, else pure white or black (one of those
 * always reaches ~4.58:1, even on mid-tones where cream and ink both fall short).
 */
export function readableForeground(brand: string): string {
    const background = isHex(brand) ? brand : ESPRESSO;
    const best = (candidates: string[]) =>
        candidates.reduce((a, b) =>
            contrastRatio(b, background) > contrastRatio(a, background) ? b : a,
        );
    const preferred = best([CREAM, INK]);

    return contrastRatio(preferred, background) >= 4.5
        ? preferred
        : best(['#FFFFFF', '#000000']);
}

/**
 * Filled stamps are saffron when that stands out from the card (3:1, the
 * non-text contrast rule), otherwise the card's own text colour: pass the
 * foreground the card already uses so stamps and text never differ.
 */
export function stampColor(brand: string, foreground?: string): string {
    const background = isHex(brand) ? brand : ESPRESSO;

    if (contrastRatio(SAFFRON, background) >= 3) {
        return SAFFRON;
    }

    return foreground && isHex(foreground)
        ? foreground
        : readableForeground(background);
}

export function isHex(value: string): boolean {
    return /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(value);
}

function luminance(hex: string): number {
    const value = hex.replace('#', '');
    const full =
        value.length === 3
            ? value
                  .split('')
                  .map((c) => c + c)
                  .join('')
            : value;
    const [r, g, b] = [0, 2, 4].map((i) => {
        const channel = parseInt(full.slice(i, i + 2), 16) / 255;

        return channel <= 0.04045
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
