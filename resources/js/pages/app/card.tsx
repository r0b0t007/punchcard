import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronLeft,
    Clock,
    ExternalLink,
    Gift,
    Link2,
    MapPin,
    Phone,
} from 'lucide-react';
import { TapLoyaltyCard } from '@/components/tap/tap-screen';
import type { TapCard } from '@/components/tap/tap-screen';
import WalletPlaceholders from '@/components/tap/wallet-placeholders';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { momentLabel } from '@/lib/local-moment';
import type { LocalMoment } from '@/lib/local-moment';
import { cn } from '@/lib/utils';
import { cards } from '@/routes/app';
import { follow } from '@/routes/app/cards';
import { index as rewards } from '@/routes/rewards';

type Props = {
    /** The enrollment. */
    id: number;
    card: TapCard;
    logoUrl: string | null;
    bannerUrl: string | null;
    paused: boolean;
    tiers: { stamps: number; reward: string; reached: boolean }[] | null;
    rewardsReady: number;
    terms: string | null;
    info: {
        hours: string | null;
        phone: string | null;
        links: { label: string; url: string }[];
    };
    history: {
        id: number;
        sourceLabel: string;
        qty: number;
        place: string;
        at: LocalMoment;
    }[];
    venues: {
        id: number;
        businessName: string;
        name: string;
        address: string | null;
        mapsUrl: string | null;
    }[];
    following: boolean;
    /** False once the café has closed: no new follow. */
    canFollow: boolean;
};

/**
 * C6 Card detail (CHW-27): one of the customer's cards in full. The card and
 * its banner, a progressive card's tiers, its reward and terms, what the
 * café tells its customers, "Follow for offers", the latest stamps and the
 * places that honour it.
 */
export default function MyCard({
    id,
    card,
    logoUrl,
    bannerUrl,
    paused,
    tiers,
    rewardsReady,
    terms,
    info,
    history,
    venues,
    following,
    canFollow,
}: Props) {
    const { t, locale } = useTranslation();
    const hasInfo =
        Boolean(info.hours) || Boolean(info.phone) || info.links.length > 0;

    return (
        <>
            <Head title={card.cardName ?? card.businessName} />
            <main className="mx-auto flex max-w-md flex-col gap-6 px-4 pt-6">
                <Link
                    href={cards()}
                    className="flex items-center gap-1 self-start text-sm text-muted-foreground"
                >
                    <ChevronLeft
                        aria-hidden="true"
                        className="size-4 rtl:rotate-180"
                    />
                    {t('My cards')}
                </Link>

                {bannerUrl ? (
                    <img
                        src={bannerUrl}
                        alt=""
                        className="h-32 w-full rounded-card object-cover"
                    />
                ) : null}
                <TapLoyaltyCard card={card} logoUrl={logoUrl} />

                {paused ? (
                    <p className="rounded-card border p-4 text-sm text-muted-foreground">
                        {t(
                            'The café has paused this card: your stamps stay on it.',
                        )}
                    </p>
                ) : null}

                {rewardsReady > 0 ? (
                    <Link
                        href={rewards()}
                        className="flex items-center gap-3 rounded-card bg-stamp p-4 font-semibold text-stamp-foreground"
                    >
                        <Gift aria-hidden="true" className="size-5 shrink-0" />
                        {t(':count reward ready|:count rewards ready', {
                            count: rewardsReady,
                        })}
                    </Link>
                ) : null}

                {tiers ? (
                    <Section title={t('Rewards on this card')}>
                        <ol className="flex flex-col gap-2">
                            {tiers.map((tier) => (
                                <li
                                    key={tier.stamps}
                                    className="flex items-center justify-between gap-3"
                                >
                                    <span>{tier.reward}</span>
                                    {tier.reached ? (
                                        <Badge>{t('Reached')}</Badge>
                                    ) : (
                                        <span className="text-sm text-muted-foreground tabular-nums">
                                            {t(':count stamp|:count stamps', {
                                                count: tier.stamps,
                                            })}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </Section>
                ) : null}

                {terms ? (
                    <Section title={t('Terms')}>
                        <p
                            dir="auto"
                            className="text-sm whitespace-pre-line text-muted-foreground"
                        >
                            {terms}
                        </p>
                    </Section>
                ) : null}

                {hasInfo ? (
                    <Section title={t('From the café')}>
                        <ul className="flex flex-col gap-2 text-sm">
                            {info.hours ? (
                                <li className="flex gap-2">
                                    <Clock
                                        aria-hidden="true"
                                        className="size-4 shrink-0"
                                    />
                                    <span
                                        dir="auto"
                                        className="whitespace-pre-line"
                                    >
                                        {info.hours}
                                    </span>
                                </li>
                            ) : null}
                            {info.phone ? (
                                <li className="flex gap-2">
                                    <Phone
                                        aria-hidden="true"
                                        className="size-4 shrink-0"
                                    />
                                    <a
                                        href={`tel:${info.phone.replace(/[^\d+]/g, '')}`}
                                        dir="ltr"
                                        className="underline underline-offset-4"
                                    >
                                        {info.phone}
                                    </a>
                                </li>
                            ) : null}
                            {info.links.map((link) => (
                                <li key={link.url} className="flex gap-2">
                                    <Link2
                                        aria-hidden="true"
                                        className="size-4 shrink-0"
                                    />
                                    <a
                                        href={link.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        dir="auto"
                                        className="underline underline-offset-4"
                                    >
                                        {link.label}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </Section>
                ) : null}

                {canFollow || following ? (
                    <section className="flex items-start gap-3 rounded-card border p-4">
                        <Checkbox
                            id="follow"
                            checked={following}
                            onCheckedChange={(checked) =>
                                router.patch(
                                    follow.url(id),
                                    { follow: checked === true },
                                    { preserveScroll: true },
                                )
                            }
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="follow">
                                {t('Follow for offers')}
                            </Label>
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    ':business can send you its offers. Turn it off whenever you like.',
                                    { business: card.businessName },
                                )}
                            </p>
                        </div>
                    </section>
                ) : null}

                <WalletPlaceholders />

                <Section title={t('Your stamps')}>
                    {history.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('No stamps on this card yet.')}
                        </p>
                    ) : (
                        <ul className="flex flex-col divide-y">
                            {history.map((stamp) => (
                                <li
                                    key={stamp.id}
                                    className="flex items-center justify-between gap-3 py-2"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">
                                            {stamp.place}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {stamp.sourceLabel} ·{' '}
                                            {momentLabel(locale, stamp.at, {
                                                today: (time) =>
                                                    t('Today at :time', {
                                                        time,
                                                    }),
                                                other: (date, time) =>
                                                    t(':date at :time', {
                                                        date,
                                                        time,
                                                    }),
                                            })}
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
                                        {stamp.qty > 0
                                            ? `+${stamp.qty}`
                                            : `−${-stamp.qty}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                {venues.length > 0 ? (
                    <Section title={t('Where to use it')}>
                        <ul className="flex flex-col gap-4">
                            {venues.map((venue) => (
                                <li key={venue.id} className="flex gap-3">
                                    <MapPin
                                        aria-hidden="true"
                                        className="mt-0.5 size-4 shrink-0"
                                    />
                                    <div className="flex min-w-0 flex-col gap-1">
                                        <p className="font-medium">
                                            {venue.businessName === venue.name
                                                ? venue.name
                                                : `${venue.businessName} · ${venue.name}`}
                                        </p>
                                        {venue.address ? (
                                            <p
                                                dir="auto"
                                                className="text-sm text-muted-foreground"
                                            >
                                                {venue.address}
                                            </p>
                                        ) : null}
                                        {venue.mapsUrl ? (
                                            <a
                                                href={venue.mapsUrl}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="flex items-center gap-1 text-sm font-medium underline underline-offset-4"
                                            >
                                                {t('Open in Maps')}
                                                <ExternalLink
                                                    aria-hidden="true"
                                                    className="size-3.5"
                                                />
                                            </a>
                                        ) : null}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Section>
                ) : null}
            </main>
        </>
    );
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-3">
            <h2 className="font-semibold">{title}</h2>
            {children}
        </section>
    );
}
