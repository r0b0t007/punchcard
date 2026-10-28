import { router } from '@inertiajs/react';
import KpiTiles from '@/components/dashboard/kpi-tiles';
import { RecentStamps, TopCustomers } from '@/components/dashboard/stamp-lists';
import type { DashboardStats } from '@/components/dashboard/types';
import VisitsChart from '@/components/dashboard/visits-chart';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';

const ALL = 'all';

/**
 * B3 lite (CHW-33): the last 30 days at the business, or one of its
 * locations. The filter reloads only the figures.
 */
export default function OwnerDashboard({ stats }: { stats: DashboardStats }) {
    const { t } = useTranslation();

    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="font-display text-xl font-bold">
                    {t('Last 30 days')}
                </h2>
                {stats.locations.length > 1 ? (
                    <Select
                        value={
                            stats.location === null
                                ? ALL
                                : String(stats.location)
                        }
                        onValueChange={(location) =>
                            router.get(
                                dashboard.url(),
                                location === ALL ? {} : { location },
                                {
                                    preserveScroll: true,
                                    preserveState: true,
                                    only: ['stats'],
                                },
                            )
                        }
                    >
                        <SelectTrigger
                            aria-label={t('Location')}
                            className="w-full sm:w-64"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                {t('All locations')}
                            </SelectItem>
                            {stats.locations.map((location) => (
                                <SelectItem
                                    key={location.id}
                                    value={String(location.id)}
                                >
                                    {location.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                ) : null}
            </div>

            <KpiTiles kpis={stats.kpis} />

            <Card>
                <CardHeader>
                    <CardTitle>{t('Visits per day')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <VisitsChart days={stats.visitsPerDay} />
                </CardContent>
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle>{t('Latest stamps')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <RecentStamps stamps={stats.recentStamps} />
                    </CardContent>
                </Card>
                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle>{t('Top 10 customers')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <TopCustomers customers={stats.topCustomers} />
                    </CardContent>
                </Card>
            </div>
        </section>
    );
}
