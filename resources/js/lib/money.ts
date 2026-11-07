/**
 * Amounts in dirhams, which the server stores in centimes (CHW-48): a
 * card's fixed reward, a business's average ticket.
 */

/** An amount typed in dirhams ("12", "12.5" or "12,50"), in centimes; anything else as typed, for the server to refuse. */
export function centimes(typed: string): string {
    const amount = typed.trim();

    return /^\d+(?:[.,]\d{1,2})?$/.test(amount)
        ? String(Math.round(Number(amount.replace(',', '.')) * 100))
        : typed;
}

/** Centimes as dirhams to type in a field again: "35", "35.5". */
export function dirhams(centimes: number): string {
    return String(centimes / 100);
}

/** Centimes as an amount in the page's language, Latin digits: "175 MAD", "175,50 MAD", "175 د.م.". */
export function formatDirhams(locale: string, centimes: number): string {
    return new Intl.NumberFormat(`${locale}-MA-u-nu-latn`, {
        style: 'currency',
        currency: 'MAD',
        minimumFractionDigits: centimes % 100 === 0 ? 0 : 2,
        maximumFractionDigits: 2,
    }).format(centimes / 100);
}
