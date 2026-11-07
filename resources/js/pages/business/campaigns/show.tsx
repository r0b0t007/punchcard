import { Head, useForm } from '@inertiajs/react';
import { Hourglass, Info } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { useState } from 'react';
import { NotificationPreview } from '@/components/campaigns/notification-preview';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { audienceName } from '@/lib/campaigns';
import type { Segment } from '@/lib/campaigns';
import { momentLabel } from '@/lib/local-moment';
import type { LocalMoment } from '@/lib/local-moment';
import { centimes, dirhams, formatDirhams } from '@/lib/money';
import { cn } from '@/lib/utils';
import { index, ticket as saveTicket } from '@/routes/campaigns';

type Campaign = {
    id: number;
    title: string;
    /** As the phone showed it: the business's name, then the title. */
    notification: string;
    body: string;
    segment: Segment;
    /** When it went, in the business's time. */
    at: LocalMoment;
    /** Followers in its audience with a phone, when it was sent. */
    audience: number;
    /** Those a phone accepted it for. */
    sent: number;
    opened: number;
};

type Results = {
    /** Days the people it reached came in, in the 48 h after it went. */
    visits: number;
    /** The same, in the same 48 h a week before. */
    baseline: number;
    extra: number;
    counting: boolean;
    /** When the 48 h end, in the business's time. */
    until: LocalMoment;
};

/**
 * A sent campaign's results (CHW-48, B10, design 19a): the campaign as a
 * phone showed it, four figures (sent, opened, visits in the 48 h after,
 * the estimated revenue), then those visits against the same 48 h a week
 * before for the same people. The revenue is the extra visits times the
 * business's average ticket, which the owner sets right here; until then
 * it asks for it. Within the 48 h it says it is still counting; a campaign
 * sent before results were recorded shows what it went to only.
 */
export default function CampaignResults({
    campaign,
    results,
    ticket,
    revenue,
}: {
    campaign: Campaign;
    results: Results | null;
    ticket: number | null;
    revenue: number | null;
}) {
    const { t, locale } = useTranslation();
    const when = momentLabel(locale, campaign.at, {
        today: (time) =>
            t('Sent today at :time · :audience', {
                time,
                audience: audienceName(t, campaign.segment),
            }),
        other: (date, time) =>
            t('Sent :date at :time · :audience', {
                date,
                time,
                audience: audienceName(t, campaign.segment),
            }),
    });

    return (
        <>
            <Head title={campaign.title} />
            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <div className="flex flex-col gap-2.5">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        <bdi>{campaign.title}</bdi>
                    </h1>
                    <p className="text-sm text-muted-foreground">{when}</p>
                    <div className="max-w-md">
                        <NotificationPreview
                            title={campaign.notification}
                            body={campaign.body}
                        />
                    </div>
                </div>

                {results === null ? (
                    <BeforeResults campaign={campaign} />
                ) : (
                    <>
                        {results.counting ? (
                            <Alert data-test="campaign-counting">
                                <Hourglass aria-hidden="true" />
                                <AlertDescription>
                                    {t(
                                        'Still counting: visits are counted for 48 hours, until :when.',
                                        {
                                            when: momentLabel(
                                                locale,
                                                results.until,
                                                {
                                                    today: (time) =>
                                                        t('today at :time', {
                                                            time,
                                                        }),
                                                    other: (date, time) =>
                                                        t(':date at :time', {
                                                            date,
                                                            time,
                                                        }),
                                                },
                                            ),
                                        },
                                    )}
                                </AlertDescription>
                            </Alert>
                        ) : null}

                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <Figure
                                label={t('Delivered')}
                                value={campaign.sent}
                                note={t(
                                    'of :count follower with a phone|of :count followers with a phone',
                                    { count: campaign.audience },
                                )}
                                test="campaign-sent"
                            />
                            <Figure
                                label={t('Opened')}
                                value={campaign.opened}
                                note={t(':percent of those sent', {
                                    percent: new Intl.NumberFormat(
                                        `${locale}-u-nu-latn`,
                                        { style: 'percent' },
                                    ).format(
                                        campaign.sent === 0
                                            ? 0
                                            : campaign.opened / campaign.sent,
                                    ),
                                })}
                                test="campaign-opened"
                            />
                            <Figure
                                label={t('Visits in 48 h')}
                                value={results.visits}
                                note={t(':count the week before', {
                                    count: results.baseline,
                                })}
                                test="campaign-visits"
                            />
                            <Revenue
                                extra={results.extra}
                                ticket={ticket}
                                revenue={revenue}
                            />
                        </div>

                        <VisitsCompared results={results} />
                    </>
                )}
            </div>
        </>
    );
}

function Figure({
    label,
    value,
    note,
    test,
    stamp = false,
    children,
}: {
    label: string;
    value?: ReactNode;
    note?: string;
    test: string;
    /** The figure that stands out: the revenue. */
    stamp?: boolean;
    children?: ReactNode;
}) {
    return (
        <Card variant={stamp ? 'stamp' : 'default'} data-test={test}>
            <CardContent>
                <div className="flex h-full flex-col gap-1">
                    <span className="text-sm text-muted-foreground">
                        {label}
                    </span>
                    {value !== undefined ? (
                        <span className="font-display text-3xl leading-tight font-bold tabular-nums">
                            <bdi>{value}</bdi>
                        </span>
                    ) : null}
                    {note ? (
                        <span className="text-sm text-muted-foreground">
                            {note}
                        </span>
                    ) : null}
                    {children}
                </div>
            </CardContent>
        </Card>
    );
}

/** The estimated revenue: extra visits × the average ticket, set here; until it is, the field asks for it. */
function Revenue({
    extra,
    ticket,
    revenue,
}: {
    extra: number;
    ticket: number | null;
    revenue: number | null;
}) {
    const { t, locale } = useTranslation();
    const [editing, setEditing] = useState(false);

    if (ticket === null || revenue === null || editing) {
        return (
            <Figure
                label={t('Estimated revenue')}
                test="campaign-revenue"
                stamp
            >
                <TicketForm ticket={ticket} onSaved={() => setEditing(false)} />
            </Figure>
        );
    }

    return (
        <Figure
            label={t('Estimated revenue')}
            value={formatDirhams(locale, revenue)}
            note={t(
                ':count extra visit × :ticket average ticket|:count extra visits × :ticket average ticket',
                { count: extra, ticket: formatDirhams(locale, ticket) },
            )}
            test="campaign-revenue"
            stamp
        >
            <Button
                variant="link"
                size="sm"
                className="self-start"
                onClick={() => setEditing(true)}
                data-test="change-ticket"
            >
                {t('Change the ticket')}
            </Button>
        </Figure>
    );
}

function TicketForm({
    ticket,
    onSaved,
}: {
    ticket: number | null;
    onSaved: () => void;
}) {
    const { t } = useTranslation();
    const form = useForm({
        average_ticket: ticket === null ? '' : dirhams(ticket),
    });

    return (
        <form
            className="flex flex-col gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((typed) => ({
                    average_ticket: centimes(typed.average_ticket),
                }));
                form.patch(saveTicket().url, {
                    preserveScroll: true,
                    onSuccess: onSaved,
                });
            }}
        >
            <label htmlFor="average-ticket" className="text-sm text-pretty">
                {t('Your average ticket, to estimate it:')}
            </label>
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    id="average-ticket"
                    inputMode="decimal"
                    dir="ltr"
                    className="w-24"
                    value={form.data.average_ticket}
                    onChange={(event) =>
                        form.setData('average_ticket', event.target.value)
                    }
                    data-test="average-ticket"
                />
                <span className="text-sm">{t('dirhams')}</span>
                <Button
                    type="submit"
                    variant="secondary"
                    disabled={form.processing}
                    data-test="save-ticket"
                >
                    {t('Save')}
                </Button>
            </div>
            <InputError message={form.errors.average_ticket} />
        </form>
    );
}

/** The 48 h after against the same 48 h a week before, as two bars, and the difference said plainly. */
function VisitsCompared({ results }: { results: Results }) {
    const { t } = useTranslation();
    const top = Math.max(results.visits, results.baseline, 1);
    const rows = [
        { label: t('In the 48 h after'), count: results.visits, base: false },
        {
            label: t('Same 48 h a week before'),
            count: results.baseline,
            base: true,
        },
    ];

    return (
        <Card data-test="campaign-compared">
            <CardContent>
                <div className="flex flex-col gap-4">
                    <h2 className="font-semibold">
                        {t('Visits by the people it went to')}
                    </h2>
                    {rows.map((row) => (
                        <div
                            key={row.label}
                            className="flex items-center gap-3 text-sm"
                        >
                            <span className="w-28 shrink-0 sm:w-40">
                                {row.label}
                            </span>
                            <span
                                className="h-3.5 min-w-0 flex-1 overflow-hidden rounded-full bg-secondary"
                                aria-hidden="true"
                            >
                                <span
                                    className={cn(
                                        'block h-full w-(--fill) rounded-full',
                                        row.base
                                            ? 'bg-muted-foreground/45'
                                            : 'bg-primary',
                                    )}
                                    style={
                                        {
                                            '--fill': `${(row.count / top) * 100}%`,
                                        } as CSSProperties
                                    }
                                />
                            </span>
                            <span className="w-10 shrink-0 text-end font-semibold tabular-nums">
                                {row.count}
                            </span>
                        </div>
                    ))}
                    <p data-test="campaign-difference">
                        {results.extra > 0
                            ? t(
                                  ':count more visit than the week before.|:count more visits than the week before.',
                                  { count: results.extra },
                              )
                            : t('No more visits than the week before.')}
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

/** Sent before results were recorded: what it went to, and why there is nothing else. */
function BeforeResults({ campaign }: { campaign: Campaign }) {
    const { t } = useTranslation();

    return (
        <>
            <Alert data-test="campaign-before-results">
                <Info aria-hidden="true" />
                <AlertDescription>
                    {t(
                        'Sent before results were recorded: what it was sent to is all there is.',
                    )}
                </AlertDescription>
            </Alert>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Figure
                    label={t('Sent to')}
                    value={campaign.audience}
                    note={t('follower with a phone|followers with a phone', {
                        count: campaign.audience,
                    })}
                    test="campaign-audience"
                />
            </div>
        </>
    );
}

CampaignResults.layout = {
    breadcrumbs: [
        { title: 'Campaigns', href: index() },
        { title: 'Results', href: '' },
    ],
};
