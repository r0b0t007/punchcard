import type { DashboardStats } from '@/components/dashboard/types';
import { useTranslation } from '@/hooks/use-translation';
import { calendarDate, momentLabel } from '@/lib/local-moment';
import { cn } from '@/lib/utils';

/**
 * The latest stamps here, newest first, with the customer and their card; a
 * correction shows as a minus. The owner's dashboard (B3), and counter mode's
 * live feed (S1, CHW-29), larger to read at arm's length.
 */
export function RecentStamps({
    stamps,
    large = false,
}: {
    stamps: DashboardStats['recentStamps'];
    large?: boolean;
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
                    className={cn(
                        'flex items-center justify-between gap-3',
                        large ? 'py-3' : 'py-2',
                    )}
                >
                    <div className="min-w-0">
                        <p
                            className={cn(
                                'truncate font-medium',
                                large && 'text-lg',
                            )}
                        >
                            {stamp.customer ?? t('A customer')}
                            {stamp.card ? ` · ${stamp.card}` : null}
                        </p>
                        <p
                            className={cn(
                                'truncate text-muted-foreground',
                                large ? 'text-sm' : 'text-xs',
                            )}
                        >
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
                            large && 'text-2xl',
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
