import type { DashboardStats } from '@/components/dashboard/types';
import { useTranslation } from '@/hooks/use-translation';
import { useState } from 'react';
import { calendarDate, momentLabel } from '@/lib/local-moment';
import { useSecond } from '@/lib/seconds';
import { cn } from '@/lib/utils';

/**
 * The latest stamps here, newest first, with the customer and their card; a
 * correction shows as a minus. The owner's dashboard (B3), and counter mode's
 * live feed (S1, CHW-29), larger to read at arm's length: there without the
 * card while every stamp is on one (BA Counter v2, CHW-220), and the newest
 * marked "Just now" while it is under a minute old, aged on the phone's
 * clock from when the feed arrived (so a feed whose polls stall stops saying
 * it).
 */
export function RecentStamps({
    stamps,
    large = false,
}: {
    stamps: DashboardStats['recentStamps'];
    large?: boolean;
}) {
    const { t, locale } = useTranslation();
    const now = useSecond();
    // When this feed arrived: secondsAgo was true then (React's "adjust state on a prop change").
    const [received, setReceived] = useState(() => ({
        stamps,
        at: Date.now(),
    }));

    if (received.stamps !== stamps) {
        setReceived({ stamps, at: Date.now() });
    }

    const justNow = (secondsAgo: number): boolean =>
        now !== null && secondsAgo + Math.max(0, now - received.at) / 1000 < 60;
    const oneCard = new Set(stamps.map((stamp) => stamp.card)).size <= 1;

    if (stamps.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('No stamps yet. They show here as customers stamp.')}
            </p>
        );
    }

    return (
        <ul className="flex flex-col divide-y">
            {stamps.map((stamp, index) => (
                <li
                    key={stamp.id}
                    className={cn(
                        'flex items-center justify-between gap-3',
                        large ? 'py-3' : 'py-2',
                    )}
                >
                    <div className="min-w-0">
                        <p
                            className={cn(
                                'truncate',
                                large ? 'text-lg font-semibold' : 'font-medium',
                            )}
                        >
                            {stamp.customer ?? t('A customer')}
                            {stamp.card && !(large && oneCard)
                                ? ` · ${stamp.card}`
                                : null}
                        </p>
                        <p
                            className={cn(
                                'truncate text-muted-foreground',
                                large ? 'text-sm' : 'text-xs',
                            )}
                        >
                            {[stamp.sourceLabel, stamp.location]
                                .filter(Boolean)
                                .join(' · ')}
                            {' · '}
                            {large &&
                            index === 0 &&
                            justNow(stamp.secondsAgo) ? (
                                <span className="font-semibold text-success">
                                    {t('Just now')}
                                </span>
                            ) : (
                                momentLabel(locale, stamp.at, {
                                    today: (time) =>
                                        t('Today at :time', { time }),
                                    other: (date, time) =>
                                        t(':date at :time', { date, time }),
                                })
                            )}
                        </p>
                    </div>
                    <span
                        // A signed count reads "+1" in Arabic too.
                        dir="ltr"
                        className={cn(
                            'shrink-0 font-display font-bold tabular-nums',
                            large && 'text-3xl',
                            stamp.qty < 0 && 'text-destructive',
                        )}
                    >
                        {stamp.qty > 0 ? `+${stamp.qty}` : `−${-stamp.qty}`}
                    </span>
                </li>
            ))}
        </ul>
    );
}

/** The 10 customers with the most visits in the last 30 days. */
export function TopCustomers({
    customers,
}: {
    customers: DashboardStats['topCustomers'];
}) {
    const { t, locale } = useTranslation();

    if (customers.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('Your regulars show here once customers visit.')}
            </p>
        );
    }

    return (
        <ol className="flex flex-col divide-y">
            {customers.map((customer, index) => (
                <li key={customer.id} className="flex items-center gap-3 py-2">
                    <span className="w-5 shrink-0 text-sm text-muted-foreground tabular-nums">
                        {index + 1}
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="truncate font-medium">{customer.name}</p>
                        <p className="text-xs text-muted-foreground">
                            {t('Last visit :date', {
                                date: calendarDate(locale, customer.lastVisit, {
                                    day: 'numeric',
                                    month: 'short',
                                }),
                            })}
                        </p>
                    </div>
                    <span className="shrink-0 text-sm tabular-nums">
                        {t(':count visit|:count visits', {
                            count: customer.visits,
                        })}
                    </span>
                </li>
            ))}
        </ol>
    );
}
