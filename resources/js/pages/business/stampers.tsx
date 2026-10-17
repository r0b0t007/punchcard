import { Head, Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { index, status } from '@/routes/stampers';

type SiteStamper = {
    id: number;
    label: string | null;
    enabled: boolean;
    /** Off until its keys are changed (punchcard support): it can't be switched on here. */
    held: boolean;
    /** The last tap on it (ISO), or null. */
    lastTapAt: string | null;
    tapsToday: number;
};

type Site = {
    id: number;
    name: string;
    timezone: string;
    stampers: SiteStamper[];
};

type RefusedTap = {
    id: number;
    at: string;
    timezone: string;
    site: string | null;
    stamper: string | null;
    stamperId: number | null;
    reason: string;
    /** A sign of tampering (a copied link, a stamper switched off or moved), not a customer's wait. */
    tampering: boolean;
    customer: string | null;
};

type Page<T> = {
    data: T[];
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

/**
 * B12, the stampers page (CHW-36, review 4a): each open site a card with
 * its stampers (enabled or not, last tap, taps today, Disable or Enable),
 * then the taps refused in the last 30 days. A reason's colour tells a
 * customer's wait (amber) from a sign of tampering (red). Disabling asks
 * once, saying what customers will see; the owner's, never staff's.
 */
export default function Stampers({
    sites,
    refused,
}: {
    sites: Site[];
    refused: Page<RefusedTap>;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Stampers')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <Heading
                    title={t('Stampers')}
                    description={t(
                        'Switch one off at once if it goes missing: a tap on it is then refused.',
                    )}
                />

                {sites.map((site) => (
                    <section
                        key={site.id}
                        className="overflow-hidden rounded-card border bg-card"
                        data-test="stampers-site"
                    >
                        <header className="flex items-center justify-between gap-3 border-b bg-muted px-4 py-3">
                            <h2 className="font-semibold">{site.name}</h2>
                            <span className="text-sm text-muted-foreground">
                                {t(':count stamper|:count stampers', {
                                    count: site.stampers.length,
                                })}
                            </span>
                        </header>
                        {site.stampers.length === 0 ? (
                            <p className="px-4 py-4 text-sm text-muted-foreground">
                                {t(
                                    'No stamper here yet: it appears once it is placed.',
                                )}
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {site.stampers.map((stamper) => (
                                    <StamperRow
                                        key={stamper.id}
                                        stamper={stamper}
                                    />
                                ))}
                            </ul>
                        )}
                    </section>
                ))}

                <section className="flex flex-col gap-3">
                    <Heading
                        variant="small"
                        title={t('Refused taps')}
                        description={t(
                            'The last 30 days. Customers saw why on their phone.',
                        )}
                    />
                    {refused.total === 0 ? (
                        <div className="flex flex-col items-center gap-1.5 rounded-card border border-dashed px-6 py-7 text-center">
                            <ShieldCheck
                                aria-hidden="true"
                                className="size-6 text-success"
                            />
                            <p className="font-semibold">
                                {t('No refused taps this month')}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'Every tap in the last 30 days gave its stamp or its reward.',
                                )}
                            </p>
                        </div>
                    ) : (
                        <RefusedTable refused={refused} />
                    )}
                </section>
            </div>
        </>
    );
}

function StamperRow({ stamper }: { stamper: SiteStamper }) {
    const { t, locale } = useTranslation();
    const name = stamper.label ?? t('Stamper :id', { id: stamper.id });

    return (
        <li
            className="grid grid-cols-2 items-center gap-x-4 gap-y-2.5 px-4 py-3.5 text-sm md:grid-cols-12"
            data-test="stamper-row"
        >
            <div className="flex min-w-0 flex-col md:col-span-4">
                <span className="truncate font-semibold">{name}</span>
                <span className="text-muted-foreground md:hidden">
                    {lastTap(t, locale, stamper.lastTapAt)} ·{' '}
                    <span className="whitespace-nowrap">
                        {t(':count today', { count: stamper.tapsToday })}
                    </span>
                </span>
            </div>
            <span
                className={cn(
                    'inline-flex w-fit items-center gap-1.5 justify-self-end rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap md:col-span-2 md:justify-self-start',
                    stamper.enabled
                        ? 'bg-success/15 text-success'
                        : 'bg-muted text-muted-foreground',
                )}
            >
                <span
                    aria-hidden="true"
                    className="size-1.5 rounded-full bg-current"
                />
                {stamper.enabled
                    ? t('Enabled')
                    : stamper.held
                      ? t('Off: punchcard support')
                      : t('Disabled')}
            </span>
            <span className="hidden text-muted-foreground md:col-span-3 md:block">
                {lastTap(t, locale, stamper.lastTapAt)}
            </span>
            <span className="hidden md:col-span-1 md:block">
                {t(':count today', { count: stamper.tapsToday })}
            </span>
            <div className="col-span-2 md:justify-self-end">
                {stamper.enabled ? (
                    <DisableStamper stamper={stamper} name={name} />
                ) : stamper.held ? (
                    <p
                        className="text-muted-foreground md:text-end"
                        data-test="held-stamper"
                    >
                        {t('Back once its keys are changed.')}
                    </p>
                ) : (
                    <Button
                        variant="outline"
                        className="w-full md:w-auto"
                        asChild
                    >
                        <Link
                            href={status(stamper.id)}
                            data={{ status: 'active' }}
                            as="button"
                            preserveScroll
                            data-test="enable-stamper"
                        >
                            {t('Enable')}
                        </Link>
                    </Button>
                )}
            </div>
        </li>
    );
}

/**
 * Disable behind a confirm that says what customers will see. The dialog
 * closes as the request starts, so it never outlives its row's state.
 */
function DisableStamper({
    stamper,
    name,
}: {
    stamper: SiteStamper;
    name: string;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="ghost-destructive"
                    className="w-full md:w-auto"
                    data-test="disable-stamper"
                >
                    {t('Disable')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    {t('Switch off :stamper?', { stamper: name })}
                </DialogTitle>
                <DialogDescription>
                    {t(
                        'A tap on it is refused until you switch it on again: customers are told to ask the staff. Scanning their QR still works.',
                    )}
                </DialogDescription>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="secondary">{t('Cancel')}</Button>
                    </DialogClose>
                    <Button variant="destructive" asChild>
                        <Link
                            href={status(stamper.id)}
                            data={{ status: 'disabled' }}
                            as="button"
                            preserveScroll
                            onStart={() => setOpen(false)}
                            data-test="confirm-disable-stamper"
                        >
                            {t('Switch off')}
                        </Link>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function RefusedTable({ refused }: { refused: Page<RefusedTap> }) {
    const { t, locale } = useTranslation();
    const reason = useReasonLabel();

    return (
        <div className="overflow-hidden rounded-card border bg-card">
            <div className="hidden grid-cols-12 gap-4 border-b bg-muted px-4 py-3 text-xs font-semibold text-muted-foreground md:grid">
                <span className="col-span-2">{t('When')}</span>
                <span className="col-span-2">{t('Where')}</span>
                <span className="col-span-2">{t('Stamper')}</span>
                <span className="col-span-4">{t('Why')}</span>
                <span className="col-span-2">{t('Customer')}</span>
            </div>
            <ul className="divide-y">
                {refused.data.map((tap) => (
                    <li
                        key={tap.id}
                        className="flex flex-col gap-1 px-4 py-3 text-sm md:grid md:grid-cols-12 md:items-center md:gap-4"
                        data-test="refused-tap"
                    >
                        <span className="text-muted-foreground md:order-none md:col-span-2 md:text-foreground">
                            {when(t, locale, tap.at, tap.timezone)}
                        </span>
                        <span className="hidden md:col-span-2 md:block">
                            {tap.site ?? '—'}
                        </span>
                        <span className="hidden md:col-span-2 md:block">
                            {stamperName(t, tap) ?? '—'}
                        </span>
                        <span
                            className={cn(
                                'order-first inline-flex items-center gap-2 font-semibold md:order-none md:col-span-4 md:font-normal',
                                tap.tampering && 'text-destructive',
                            )}
                        >
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'size-2 shrink-0 rounded-xs',
                                    tap.tampering
                                        ? 'bg-destructive'
                                        : 'bg-warning',
                                )}
                            />
                            {reason(tap.reason)}
                        </span>
                        <span
                            className={cn(
                                'md:col-span-2',
                                tap.customer === null &&
                                    'text-muted-foreground',
                            )}
                        >
                            <span className="md:hidden">
                                {[tap.site, stamperName(t, tap)]
                                    .filter(Boolean)
                                    .join(' · ')}{' '}
                                ·{' '}
                            </span>
                            {tap.customer ?? t('A customer')}
                        </span>
                    </li>
                ))}
            </ul>
            <nav
                className="flex items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"
                aria-label={t('Refused taps')}
            >
                <span>
                    {t(':from-:to of :total', {
                        from: refused.from ?? 0,
                        to: refused.to ?? 0,
                        total: refused.total,
                    })}
                </span>
                <span className="flex gap-4">
                    {refused.prev_page_url ? (
                        <Link
                            href={refused.prev_page_url}
                            preserveScroll
                            className="underline underline-offset-4"
                        >
                            {t('Newer')}
                        </Link>
                    ) : null}
                    {refused.next_page_url ? (
                        <Link
                            href={refused.next_page_url}
                            preserveScroll
                            className="underline underline-offset-4"
                        >
                            {t('Older')}
                        </Link>
                    ) : null}
                </span>
            </nav>
        </div>
    );
}

/** Why a tap was refused, in an owner's words (TapRejection::visibleToBusiness). */
function useReasonLabel(): (reason: string) => string {
    const { t } = useTranslation();

    return (reason) =>
        ({
            cooldown: t('Too soon after the last stamp'),
            daily_cap: t("Today's limit reached"),
            stamper_disabled: t('Stamper switched off'),
            replay: t('Copied or replayed link'),
            unassigned_tag: t('Stamper moved or removed'),
            card_inactive: t('Card paused'),
            not_honoured: t('Card not accepted here'),
            site_closed: t('Site closed'),
            card_misconfigured: t('Card settings to fix'),
            already_redeemed: t('Reward already handed over'),
        })[reason] ?? reason;
}

type Translate = ReturnType<typeof useTranslation>['t'];

/** The stamper a tap was refused on, by its label or, without one, its number (as in its site's list). */
function stamperName(t: Translate, tap: RefusedTap): string | null {
    return (
        tap.stamper ??
        (tap.stamperId === null
            ? null
            : t('Stamper :id', { id: tap.stamperId }))
    );
}

/** "Last tap 12 minutes ago", or that it was never tapped. */
function lastTap(t: Translate, locale: string, at: string | null): string {
    if (at === null) {
        return t('Never tapped yet');
    }

    const seconds = Math.round((new Date(at).getTime() - Date.now()) / 1000);
    const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    const ago =
        Math.abs(seconds) < 3600
            ? format.format(Math.round(seconds / 60), 'minute')
            : Math.abs(seconds) < 86_400
              ? format.format(Math.round(seconds / 3600), 'hour')
              : format.format(Math.round(seconds / 86_400), 'day');

    return t('Last tap :ago', { ago });
}

/** "Today 10:42", "Yesterday 18:03" or "16 Oct 12:30", in the site's time. */
function when(
    t: Translate,
    locale: string,
    at: string,
    timezone: string,
): string {
    const moment = new Date(at);
    const day = (date: Date) =>
        new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(date);
    const time = new Intl.DateTimeFormat(locale, {
        timeZone: timezone,
        hour: '2-digit',
        minute: '2-digit',
    }).format(moment);

    if (day(moment) === day(new Date())) {
        return t('Today :time', { time });
    }

    if (day(moment) === day(new Date(Date.now() - 86_400_000))) {
        return t('Yesterday :time', { time });
    }

    return `${new Intl.DateTimeFormat(locale, { timeZone: timezone, day: 'numeric', month: 'short' }).format(moment)} ${time}`;
}

Stampers.layout = {
    breadcrumbs: [
        {
            title: 'Stampers',
            href: index(),
        },
    ],
};
