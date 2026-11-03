import { Link } from '@inertiajs/react';
import { ChevronRight, Megaphone, Moon } from 'lucide-react';
import type { DashboardStats } from '@/components/dashboard/types';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import { initials } from '@/lib/customers';
import { create } from '@/routes/campaigns';
import { index, show } from '@/routes/customers';

/**
 * Who is going quiet at the business (CHW-45, design 16): how many, the 5
 * with the most visits, the Customers page's Going quiet tab, and a
 * campaign to them. The whole business's, so with one location picked it
 * says so. With nobody going quiet it says what it is for.
 */
export default function GoingQuiet({
    goingQuiet,
    locationPicked,
}: {
    goingQuiet: DashboardStats['goingQuiet'];
    locationPicked: boolean;
}) {
    const { t } = useTranslation();
    const anyone = goingQuiet.count > 0;

    return (
        <Card data-test="going-quiet">
            <CardHeader>
                <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 flex-col gap-1.5">
                        <div className="flex items-center gap-2">
                            <CardTitle>{t('Going quiet')}</CardTitle>
                            <span
                                className="rounded-full bg-warning/15 px-2.5 py-0.5 text-xs font-semibold text-warning tabular-nums"
                                data-test="going-quiet-count"
                            >
                                {goingQuiet.count}
                            </span>
                        </div>
                        <CardDescription>
                            {locationPicked
                                ? t(
                                      'At every location: late for their usual visit, not lost yet.',
                                  )
                                : t(
                                      'Late for their usual visit, not lost yet.',
                                  )}
                        </CardDescription>
                    </div>
                    {anyone ? (
                        <Link
                            href={index({ query: { segment: 'quiet' } })}
                            className="inline-flex shrink-0 items-center gap-1 text-sm font-medium underline underline-offset-4"
                        >
                            {t('See all')}
                            <ChevronRight
                                aria-hidden="true"
                                className="size-4 rtl:rotate-180"
                            />
                        </Link>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent>
                {anyone ? (
                    <>
                        <ul className="flex flex-col divide-y border-t">
                            {goingQuiet.customers.map((customer) => (
                                <li key={customer.id}>
                                    <Link
                                        href={show(customer.id)}
                                        className="flex items-center gap-3 py-2 text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold"
                                        >
                                            {initials(customer.name)}
                                        </span>
                                        <div className="flex min-w-0 flex-1 flex-col">
                                            <span className="truncate font-semibold">
                                                <bdi>{customer.name}</bdi>
                                            </span>
                                            <span className="truncate text-xs text-muted-foreground">
                                                {[
                                                    customer.usualGapDays ===
                                                    null
                                                        ? null
                                                        : t(
                                                              'Usually every day|Usually every :count days',
                                                              {
                                                                  count: customer.usualGapDays,
                                                              },
                                                          ),
                                                    t(
                                                        'Last visit :count day ago|Last visit :count days ago',
                                                        {
                                                            count: customer.lastVisitDays,
                                                        },
                                                    ),
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>
                                        </div>
                                        <span className="shrink-0 tabular-nums">
                                            {t(':count visit|:count visits', {
                                                count: customer.visits,
                                            })}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm text-pretty text-muted-foreground">
                                {t(
                                    'The nudge goes to those of them who follow you for offers.',
                                )}
                            </p>
                            <Button asChild className="shrink-0">
                                <Link
                                    href={create({
                                        query: { segment: 'going_quiet' },
                                    })}
                                    data-test="going-quiet-nudge"
                                >
                                    <Megaphone aria-hidden="true" />
                                    {t('Send them a nudge')}
                                </Link>
                            </Button>
                        </div>
                    </>
                ) : (
                    <div className="flex flex-col items-center gap-1.5 rounded-md border border-dashed p-5 text-center">
                        <Moon aria-hidden="true" className="size-6" />
                        <p className="font-semibold">
                            {t('Nobody is going quiet')}
                        </p>
                        <p className="text-sm text-pretty text-muted-foreground">
                            {t(
                                'Customers late for their usual visit show here, so you can bring them back.',
                            )}
                        </p>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
