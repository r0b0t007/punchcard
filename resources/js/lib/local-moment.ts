/** A moment in the location's time (LocalMoment on the server). */
export type LocalMoment = {
    day: 'today' | 'other';
    /** Y-m-d */
    date: string;
    /** H:i */
    time: string;
};

/** One formatter per locale and options: building an Intl formatter is costly. */
const calendarFormats = new Map<string, Intl.DateTimeFormat>();
const deviceFormats = new Map<string, Intl.DateTimeFormat>();

/**
 * A moment (ISO 8601) as a date in the device's timezone, with Latin digits:
 * for what is the customer's own, who has no site (a consent's date, CHW-58).
 */
export function localDate(
    locale: string,
    iso: string,
    options: Intl.DateTimeFormatOptions,
): string {
    const key = `${locale}|${JSON.stringify(options)}`;
    let format = deviceFormats.get(key);

    if (!format) {
        format = new Intl.DateTimeFormat(`${locale}-u-nu-latn`, options);
        deviceFormats.set(key, format);
    }

    return format.format(new Date(iso));
}

/**
 * A calendar date (Y-m-d) in the locale, with Latin digits as everywhere in
 * the app (Morocco writes Arabic with them). Formatted in UTC, so no
 * timezone shifts the day.
 */
export function calendarDate(
    locale: string,
    date: string,
    options: Intl.DateTimeFormatOptions,
): string {
    const key = `${locale}|${JSON.stringify(options)}`;
    let format = calendarFormats.get(key);

    if (!format) {
        format = new Intl.DateTimeFormat(`${locale}-u-nu-latn`, {
            ...options,
            timeZone: 'UTC',
        });
        calendarFormats.set(key, format);
    }

    return format.format(new Date(`${date}T00:00:00Z`));
}

/**
 * "… at 10:42" today, "… on Monday 5 October at 10:42" another day: the
 * caller words each (its own t() calls, so the keys stay findable), this
 * formats the date in the locale.
 */
export function momentLabel(
    locale: string,
    moment: LocalMoment,
    words: {
        today: (time: string) => string;
        other: (date: string, time: string) => string;
    },
): string {
    if (moment.day === 'today') {
        return words.today(moment.time);
    }

    return words.other(
        calendarDate(locale, moment.date, {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
        }),
        moment.time,
    );
}
