import type { DashboardStats } from '@/components/dashboard/types';
import { useTranslation } from '@/hooks/use-translation';
import { momentLabel } from '@/lib/local-moment';
import { cn } from '@/lib/utils';

/** The latest stamps here, newest first; a correction shows as a minus. */
export function RecentStamps({
    stamps,
}: {
    stamps: DashboardStats['recentStamps'];
}) {
    const { t, locale } = useTranslation();

    if (stamps.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('No stamps yet. They show here as customers stamp.')}
            </p>
        );
    }

    return (
        <ul className="flex flex-col divide-y">
            {stamps.map((stamp) => (
                <li
                    key={stamp.id}
                    className="flex items-center justify-between gap-3 py-2"
                >
                    <div className="min-w-0">
                        <p className="truncate font-medium">
                            {stamp.customer ?? t('A customer')}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {[
                                stamp.sourceLabel,
                                stamp.location,
                                momentLabel(locale, stamp.at, {
                                    today: (time) =>
                                        t('Today at :time', { time }),
                                    other: (date, time) =>
                                        t(':date at :time', { date, time }),
                                }),
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                    </div>
                    <span
                        // A signed count reads "+1" in Arabic too.
                        dir="ltr"
                        className={cn(
                            'shrink-0 font-display font-bold tabular-nums',
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
    // A calendar date: format it in UTC so no timezone shifts the day.
    const format = new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });

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
                                date: format.format(
                                    new Date(`${customer.lastVisit}T00:00:00Z`),
                                ),
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
