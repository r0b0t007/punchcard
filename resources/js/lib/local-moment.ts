/** A moment in the location's time (LocalMoment on the server). */
export type LocalMoment = {
    day: 'today' | 'other';
    /** Y-m-d */
    date: string;
    /** H:i */
    time: string;
};

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
        // A calendar date: format it in UTC so no timezone shifts the day.
        new Intl.DateTimeFormat(locale, {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            timeZone: 'UTC',
        }).format(new Date(`${moment.date}T00:00:00Z`)),
        moment.time,
    );
}
