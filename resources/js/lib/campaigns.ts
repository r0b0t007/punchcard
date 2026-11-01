/**
 * A campaign's words (CHW-42, design 12d), as CampaignText counts them on
 * the server: the notification's title is the business's name, then the
 * owner's title, TITLE characters in all; the title keeps at least
 * TITLE_MIN of them, a longer name is cut to fit. Characters, not UTF-16
 * units, as PHP's mb_strlen: an emoji or an Arabic letter is one.
 */
export const TITLE = 40;
export const TITLE_MIN = 15;

const BETWEEN = ' · ';

/** How many characters a value has, as mb_strlen counts them (code points). */
export function characters(value: string): number {
    return Array.from(value).length;
}

/** How long the owner's title may be beside this business's name. */
export function titleRoom(business: string): number {
    return Math.max(
        TITLE_MIN,
        TITLE - characters(BETWEEN) - characters(business),
    );
}

/** The notification's title, as the server sends it. */
export function notificationTitle(business: string, title: string): string {
    const room = TITLE - characters(BETWEEN) - characters(title);
    const name =
        characters(business) > room
            ? `${Array.from(business)
                  .slice(0, Math.max(0, room - 1))
                  .join('')}…`
            : business;

    return `${name}${BETWEEN}${title}`;
}

/** The composer's idempotency key, one per page load (a version 4 UUID). */
export function newIdempotencyKey(): string {
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    // Outside a secure context (a phone on the café's network over plain http), from random bytes.
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes).map((byte) =>
        byte.toString(16).padStart(2, '0'),
    );

    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}
