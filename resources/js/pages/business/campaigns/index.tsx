import { Head, Link } from '@inertiajs/react';
import { Megaphone, Plus } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { TranslateFn } from '@/hooks/use-translation';
import { momentLabel } from '@/lib/local-moment';
import type { LocalMoment } from '@/lib/local-moment';
import { cn } from '@/lib/utils';
import { create, index } from '@/routes/campaigns';

type Segment = 'all' | 'quiet_14d' | 'top_10';
type Status = 'scheduled' | 'sending' | 'sent' | 'canceled';

type CampaignRow = {
    id: number;
    title: string;
    segment: Segment;
    status: Status;
    /** When the owner sent it, or when it goes, in the business's time. */
    at: LocalMoment;
    /** Who it went, or goes, to: followers in the audience with a phone. */
    people: number;
};

/**
 * The Campaigns page (CHW-42, design 12c): the business's latest
 * campaigns, newest first, with who they went to, when (in the business's
 * time) and how many they reached; and how many of the week's are left.
 * The owner's, never staff's.
 */
export default function Campaigns({
    campaigns,
    left,
    max,
}: {
    campaigns: CampaignRow[];
    left: number;
    max: number;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Campaigns')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title={t('Campaigns')}
                        description={t(
                            ':count of :max campaigns left this week.',
                            {
                                count: left,
                                max,
                            },
                        )}
                    />
                    <Button asChild>
                        <Link href={create()} data-test="new-campaign">
                            <Plus aria-hidden="true" />
                            {t('New campaign')}
                        </Link>
                    </Button>
                </div>

                {campaigns.length === 0 ? (
                    <div
                        className="flex flex-col items-center gap-2 rounded-card border border-dashed px-5 py-7 text-center"
                        data-test="campaigns-empty"
                    >
                        <Megaphone aria-hidden="true" className="size-6" />
                        <p className="font-semibold">{t('No campaigns yet')}</p>
                        <p className="text-sm text-pretty text-muted-foreground">
                            {t(
                                'Send your followers an offer to fill a slow day. They only get it if they follow you for offers.',
                            )}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-card border bg-card">
                        <div className="hidden grid-cols-12 gap-4 border-b bg-muted px-4 py-3 text-xs font-semibold text-muted-foreground md:grid">
                            <span className="col-span-4">{t('Campaign')}</span>
                            <span className="col-span-3">{t('Who')}</span>
                            <span className="col-span-3">{t('When')}</span>
                            <span className="col-span-2 text-end">
                                {t('Recipients')}
                            </span>
                        </div>
                        <ul className="divide-y">
                            {campaigns.map((campaign) => (
                                <CampaignItem
                                    key={campaign.id}
                                    campaign={campaign}
                                />
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </>
    );
}

function CampaignItem({ campaign }: { campaign: CampaignRow }) {
    const { t, locale } = useTranslation();
    const who = audience(t, campaign.segment);
    const when = moment(t, locale, campaign);

    return (
        <li
            className="flex items-center gap-4 px-4 py-3.5 text-sm md:grid md:grid-cols-12"
            data-test="campaign-row"
        >
            <div className="flex min-w-0 flex-1 flex-col md:col-span-4">
                {/* The owner's words, in their own direction, aligned with the page's. */}
                <span className="truncate font-semibold">
                    <bdi>{campaign.title}</bdi>
                </span>
                <span className="text-muted-foreground md:hidden">
                    {who} · {when}
                </span>
            </div>
            <span className="hidden text-muted-foreground md:col-span-3 md:block">
                {who}
            </span>
            <span
                className={cn(
                    'hidden w-fit items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap md:col-span-3 md:inline-flex',
                    campaign.status === 'sent' && 'bg-success/15 text-success',
                    (campaign.status === 'sending' ||
                        campaign.status === 'scheduled') &&
                        'bg-warning/15 text-warning',
                    campaign.status === 'canceled' &&
                        'bg-muted text-muted-foreground',
                )}
            >
                <span
                    aria-hidden="true"
                    className="size-1.5 rounded-full bg-current"
                />
                {when}
            </span>
            <span className="shrink-0 text-muted-foreground md:col-span-2 md:justify-self-end">
                {campaign.status === 'canceled'
                    ? null
                    : t(':count person|:count people', {
                          count: campaign.people,
                      })}
            </span>
        </li>
    );
}

function audience(t: TranslateFn, segment: Segment): string {
    return {
        all: t('All followers'),
        quiet_14d: t('Not seen in 14 days'),
        top_10: t('Top 10'),
    }[segment];
}

/** "Sent today 10:02", "Sent 28 Oct", "Sending", "Canceled", or when a scheduled one goes ("8 Nov, 09:00"). */
function moment(t: TranslateFn, locale: string, campaign: CampaignRow): string {
    if (campaign.status === 'sending') {
        return t('Sending');
    }

    if (campaign.status === 'canceled') {
        return t('Canceled');
    }

    const short: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'short',
    };

    return campaign.status === 'scheduled'
        ? momentLabel(
              locale,
              campaign.at,
              {
                  today: (time) => t('Today :time', { time }),
                  other: (date, time) => t(':date, :time', { date, time }),
              },
              short,
          )
        : momentLabel(
              locale,
              campaign.at,
              {
                  today: (time) => t('Sent today :time', { time }),
                  other: (date) => t('Sent :date', { date }),
              },
              short,
          );
}

Campaigns.layout = {
    breadcrumbs: [
        {
            title: 'Campaigns',
            href: index(),
        },
    ],
};
