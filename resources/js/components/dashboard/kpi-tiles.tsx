import { Minus, TrendingDown, TrendingUp } from 'lucide-react';
import type { DashboardStats, Kpi } from '@/components/dashboard/types';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/** The four figures, in order; the return rate changes in points, the rest in percent. */
const TILES = [
    { key: 'activeCustomers', label: 'Active customers', points: false },
    { key: 'visits', label: 'Visits', points: false },
    { key: 'returnRate', label: 'Return rate', points: true },
    { key: 'rewardsRedeemed', label: 'Rewards redeemed', points: false },
] as const;

/**
 * Numbers in the locale's own way ("85 %" in French, its sign and percent
 * placed for Arabic), with Latin digits as everywhere else in the app.
 */
function numberFormats(locale: string) {
    const options = `${locale}-u-nu-latn`;

    return {
        percent: new Intl.NumberFormat(options, { style: 'percent' }),
        signedPercent: new Intl.NumberFormat(options, {
            style: 'percent',
            signDisplay: 'exceptZero',
        }),
        signed: new Intl.NumberFormat(options, { signDisplay: 'exceptZero' }),
    };
}

/** Built once per locale: Intl formatters are costly to construct. */
const numberFormatsByLocale = new Map<
    string,
    ReturnType<typeof numberFormats>
>();

function numberFormatsFor(locale: string): ReturnType<typeof numberFormats> {
    let formats = numberFormatsByLocale.get(locale);

    if (!formats) {
        formats = numberFormats(locale);
        numberFormatsByLocale.set(locale, formats);
    }

    return formats;
}

/** B3's KPI tiles: 2 by 2 on a phone, 4 across on a wide screen. */
export default function KpiTiles({ kpis }: { kpis: DashboardStats['kpis'] }) {
    const { t, locale } = useTranslation();
    const formats = numberFormatsFor(locale);

    return (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            {TILES.map((tile) => {
                const kpi = kpis[tile.key];

                return (
                    <Card key={tile.key}>
                        <CardHeader>
                            <CardDescription>{t(tile.label)}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-col gap-1">
                                <p className="font-display text-title font-bold tabular-nums">
                                    <bdi>
                                        {tile.key === 'returnRate'
                                            ? formats.percent.format(
                                                  kpi.value / 100,
                                              )
                                            : kpi.value}
                                    </bdi>
                                </p>
                                <Change
                                    kpi={kpi}
                                    points={tile.points}
                                    formats={formats}
                                />
                            </div>
                        </CardContent>
                    </Card>
                );
            })}
        </div>
    );
}

/** The change against the 30 days before, with an arrow as well as a colour. */
function Change({
    kpi,
    points,
    formats,
}: {
    kpi: Kpi;
    points: boolean;
    formats: ReturnType<typeof numberFormats>;
}) {
    const { t, tn } = useTranslation();

    if (kpi.change === null) {
        return (
            <p className="text-xs text-muted-foreground">
                {kpi.value > 0 ? t('New in the last 30 days') : '—'}
            </p>
        );
    }

    const Icon =
        kpi.change > 0 ? TrendingUp : kpi.change < 0 ? TrendingDown : Minus;
    // Isolated, so a sign never lands on the wrong side in Arabic.
    const change = (
        <bdi>
            {points
                ? formats.signed.format(kpi.change)
                : formats.signedPercent.format(kpi.change / 100)}
        </bdi>
    );

    return (
        <p
            className={cn(
                'flex items-center gap-1 text-xs',
                kpi.change > 0 && 'text-success',
                kpi.change < 0 && 'text-destructive',
                kpi.change === 0 && 'text-muted-foreground',
            )}
        >
            <Icon aria-hidden="true" className="size-3.5 shrink-0" />
            <span>
                {points
                    ? tn(':change pts vs the 30 days before', { change })
                    : tn(':change vs the 30 days before', { change })}
            </span>
        </p>
    );
}
