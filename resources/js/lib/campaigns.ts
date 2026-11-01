/**
 * A campaign's words (CHW-42, design 12d), as CampaignText counts them on
 * the server: the notification's title is the business's name, then the
 * owner's title, TITLE characters in all, a long name cut to fit (the room
 * the title gets comes from the server). Characters, not UTF-16 units, as
 * PHP's mb_strlen: an emoji or an Arabic letter is one.
 */
const TITLE = 40;

const BETWEEN = ' · ';

/** How many characters a value has, as mb_strlen counts them (code points). */
export function characters(value: string): number {
    return Array.from(value).length;
}

/** What is typed, cut to max characters: the field stops where the counter and the server do. */
export function clip(value: string, max: number): string {
    const all = Array.from(value);

    return all.length > max ? all.slice(0, max).join('') : value;
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

/** A calendar date (Y-m-d) some days on, by the calendar: no timezone moves it. */
export function addDays(date: string, days: number): string {
    const day = new Date(`${date}T00:00:00Z`);
    day.setUTCDate(day.getUTCDate() + days);

    return day.toISOString().slice(0, 10);
}

/** A later campaign's first suggestion: the next full hour after now (Y-m-d, H:i), tomorrow at midnight after 23:00. */
export function nextHour(
    date: string,
    time: string,
): { date: string; time: string } {
    const hour = Number(time.slice(0, 2)) + 1;

    return hour > 23
        ? { date: addDays(date, 1), time: '00:00' }
        : { date, time: `${String(hour).padStart(2, '0')}:00` };
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
