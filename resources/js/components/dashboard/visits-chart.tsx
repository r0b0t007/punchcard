import type { DashboardStats } from '@/components/dashboard/types';
import { useTranslation } from '@/hooks/use-translation';
import { calendarDate } from '@/lib/local-moment';

/** Bar width and gap in the chart's own units: 30 bars fill 300. */
const STEP = 10;
const HEIGHT = 100;

/**
 * Visits per day over the last 30 days (B3): an SVG bar chart, read in the
 * reading direction (mirrored in Arabic), with a table for screen readers.
 */
export default function VisitsChart({
    days,
}: {
    days: DashboardStats['visitsPerDay'];
}) {
    const { t, locale } = useTranslation();
    const highest = Math.max(1, ...days.map((day) => day.visits));
    const dateOf = (date: string) =>
        calendarDate(locale, date, { day: 'numeric', month: 'short' });
    const visitsOn = (day: DashboardStats['visitsPerDay'][number]) =>
        t(':count visit on :date|:count visits on :date', {
            date: dateOf(day.date),
            count: day.visits,
        });

    return (
        <figure className="flex flex-col gap-2">
            <svg
                viewBox={`0 0 ${days.length * STEP} ${HEIGHT}`}
                preserveAspectRatio="none"
                aria-hidden="true"
                className="h-40 w-full rtl:-scale-x-100"
            >
                {days.map((day, index) => {
                    const height = (day.visits / highest) * (HEIGHT - 4);

                    return (
                        <rect
                            key={day.date}
                            x={index * STEP + 1}
                            y={HEIGHT - height}
                            width={STEP - 2}
                            height={height}
                            rx={1}
                            className="fill-primary"
                        >
                            <title>{visitsOn(day)}</title>
                        </rect>
                    );
                })}
                <line
                    x1={0}
                    y1={HEIGHT - 0.5}
                    x2={days.length * STEP}
                    y2={HEIGHT - 0.5}
                    vectorEffect="non-scaling-stroke"
                    className="stroke-border"
                />
            </svg>
            {days.length > 0 ? (
                <div
                    aria-hidden="true"
                    className="flex justify-between text-xs text-muted-foreground"
                >
                    <span>{dateOf(days[0].date)}</span>
                    <span>
                        {dateOf(days[Math.floor(days.length / 2)].date)}
                    </span>
                    <span>{dateOf(days[days.length - 1].date)}</span>
                </div>
            ) : null}
            <table className="sr-only">
                <caption>{t('Visits per day, last 30 days')}</caption>
                <tbody>
                    {days.map((day) => (
                        <tr key={day.date}>
                            <td>{visitsOn(day)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </figure>
    );
}
