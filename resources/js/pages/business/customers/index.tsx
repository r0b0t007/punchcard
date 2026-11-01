import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Contact, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { TranslateFn } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { index } from '@/routes/customers';

type Segment = 'all' | 'regulars' | 'quiet' | 'lost' | 'new';
type Status = 'active' | 'quiet' | 'lost';

type CustomerRow = {
    id: number;
    /** First name and initial: never more. */
    name: string;
    visits: number;
    /** Days since their last visit, in the business's time: 0 is today. */
    lastVisitDays: number;
    rewardsRedeemed: number;
    status: Status;
};

type Page = {
    current: number;
    last: number;
    from: number | null;
    to: number | null;
    total: number;
};

const SEGMENTS: Segment[] = ['all', 'regulars', 'quiet', 'lost', 'new'];

/**
 * The Customers page (CHW-44, B4, design 15a): everyone who came in to the
 * business, a tab per segment with its count, search by first name, 25 a
 * page. The owner's, never staff's.
 */
export default function Customers({
    segment,
    q,
    counts,
    customers,
    page,
}: {
    segment: Segment;
    q: string | null;
    counts: Record<Segment, number>;
    customers: CustomerRow[];
    page: Page;
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState(q ?? '');

    // Looks the name up shortly after typing stops, staying on the tab; only when it differs from the one shown,
    // so switching tabs or pages never searches again.
    useEffect(() => {
        if (search.trim() === (q ?? '')) {
            return;
        }

        const timer = window.setTimeout(() => {
            router.get(
                index({ query: query(segment, search, 1) }),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => window.clearTimeout(timer);
    }, [search, segment, q]);

    return (
        <>
            <Head title={t('Customers')} />
            <div className="flex max-w-5xl flex-col gap-5 p-4">
                <Heading
                    title={t('Customers')}
                    description={t(
                        'Everyone who came in, and who has stopped coming.',
                    )}
                />

                <div className="relative">
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute start-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder={t('Search by first name')}
                        aria-label={t('Search by first name')}
                        maxLength={60}
                        className="ps-10"
                        data-test="customers-search"
                    />
                </div>

                <nav
                    aria-label={t('Customers')}
                    className="flex gap-1 overflow-x-auto border-b"
                >
                    {SEGMENTS.map((each) => (
                        <Link
                            key={each}
                            href={index({ query: query(each, search, 1) })}
                            preserveState
                            aria-current={each === segment ? 'page' : undefined}
                            className={cn(
                                '-mb-px inline-flex h-10 shrink-0 items-center gap-2 border-b-2 px-3 text-sm whitespace-nowrap',
                                each === segment
                                    ? 'border-primary font-semibold text-foreground'
                                    : 'border-transparent text-muted-foreground',
                            )}
                            data-test={`customers-tab-${each}`}
                        >
                            {segmentName(t, each)}
                            <span
                                className={cn(
                                    'rounded-full px-2 text-xs font-semibold',
                                    each === segment
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {counts[each]}
                            </span>
                        </Link>
                    ))}
                </nav>

                {customers.length === 0 ? (
                    <Empty searching={q !== null} none={counts.all === 0} />
                ) : (
                    <>
                        <div className="overflow-hidden rounded-card border bg-card">
                            <div className="hidden grid-cols-12 gap-4 border-b bg-muted px-4 py-3 text-xs font-semibold text-muted-foreground md:grid">
                                <span className="col-span-4">{t('Name')}</span>
                                <span className="col-span-2">
                                    {t('Visits')}
                                </span>
                                <span className="col-span-2">
                                    {t('Last visit')}
                                </span>
                                <span className="col-span-2">
                                    {t('Rewards redeemed')}
                                </span>
                                <span className="col-span-2">
                                    {t('Status')}
                                </span>
                            </div>
                            <ul className="divide-y">
                                {customers.map((customer) => (
                                    <CustomerItem
                                        key={customer.id}
                                        customer={customer}
                                    />
                                ))}
                            </ul>
                        </div>

                        <div className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
                            <span>
                                {t(':from–:to of :total', {
                                    from: page.from ?? 0,
                                    to: page.to ?? 0,
                                    total: page.total,
                                })}
                            </span>
                            <div className="flex gap-2">
                                <PageLink
                                    page={page.current - 1}
                                    enabled={page.current > 1}
                                    segment={segment}
                                    search={search}
                                >
                                    <ChevronLeft
                                        aria-hidden="true"
                                        className="rtl:rotate-180"
                                    />
                                    {t('Previous')}
                                </PageLink>
                                <PageLink
                                    page={page.current + 1}
                                    enabled={page.current < page.last}
                                    segment={segment}
                                    search={search}
                                >
                                    {t('Next')}
                                    <ChevronRight
                                        aria-hidden="true"
                                        className="rtl:rotate-180"
                                    />
                                </PageLink>
                            </div>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

function CustomerItem({ customer }: { customer: CustomerRow }) {
    const { t } = useTranslation();
    const last = lastVisit(t, customer.lastVisitDays);

    return (
        <li
            className="flex items-center gap-4 px-4 py-3 text-sm md:grid md:grid-cols-12"
            data-test="customer-row"
        >
            <div className="flex min-w-0 flex-1 items-center gap-3 md:col-span-4">
                <span
                    aria-hidden="true"
                    className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold"
                >
                    {initials(customer.name)}
                </span>
                <div className="flex min-w-0 flex-col">
                    {/* The customer's own name, in its own direction. */}
                    <span className="truncate font-semibold">
                        <bdi>{customer.name}</bdi>
                    </span>
                    <span className="text-xs text-muted-foreground md:hidden">
                        {t(':count visit|:count visits', {
                            count: customer.visits,
                        })}{' '}
                        · {last} ·{' '}
                        {t(':count redeemed', {
                            count: customer.rewardsRedeemed,
                        })}
                    </span>
                </div>
            </div>
            <span className="hidden md:col-span-2 md:block">
                {customer.visits}
            </span>
            <span className="hidden text-muted-foreground md:col-span-2 md:block">
                {last}
            </span>
            <span className="hidden md:col-span-2 md:block">
                {customer.rewardsRedeemed}
            </span>
            <span className="md:col-span-2">
                <StatusChip status={customer.status} />
            </span>
        </li>
    );
}

function StatusChip({ status }: { status: Status }) {
    const { t } = useTranslation();

    return (
        <span
            className={cn(
                'inline-flex w-fit items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap',
                status === 'active' && 'bg-success/15 text-success',
                status === 'quiet' && 'bg-warning/15 text-warning',
                status === 'lost' && 'bg-muted text-muted-foreground',
            )}
            data-test="customer-status"
        >
            <span
                aria-hidden="true"
                className="size-1.5 rounded-full bg-current"
            />
            {status === 'active'
                ? t('Active')
                : status === 'quiet'
                  ? t('Going quiet')
                  : t('Lost')}
        </span>
    );
}

function PageLink({
    page,
    enabled,
    segment,
    search,
    children,
}: {
    page: number;
    enabled: boolean;
    segment: Segment;
    search: string;
    children: React.ReactNode;
}) {
    if (!enabled) {
        return (
            <Button variant="outline" size="sm" disabled>
                {children}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild>
            <Link
                href={index({ query: query(segment, search, page) })}
                preserveState
            >
                {children}
            </Link>
        </Button>
    );
}

function Empty({ searching, none }: { searching: boolean; none: boolean }) {
    const { t } = useTranslation();

    return (
        <div
            className="flex flex-col items-center gap-2 rounded-card border border-dashed px-5 py-7 text-center"
            data-test="customers-empty"
        >
            <Contact aria-hidden="true" className="size-6" />
            <p className="font-semibold">
                {searching
                    ? t('No one by that name')
                    : none
                      ? t('No customers yet')
                      : t('No one here yet')}
            </p>
            <p className="text-sm text-pretty text-muted-foreground">
                {searching
                    ? t(
                          'Search looks at first names. Try fewer letters, or another tab.',
                      )
                    : t(
                          'Customers appear here after their first stamp: a tap, a scan of their QR, or a stamp added by hand.',
                      )}
            </p>
        </div>
    );
}

/** The list's address: the tab, what was typed, the page; the defaults left out. */
function query(
    segment: Segment,
    search: string,
    page: number,
): Record<string, string | number> {
    return {
        ...(segment === 'all' ? {} : { segment }),
        ...(search.trim() === '' ? {} : { q: search.trim() }),
        ...(page === 1 ? {} : { page }),
    };
}

function segmentName(t: TranslateFn, segment: Segment): string {
    switch (segment) {
        case 'all':
            return t('All');
        case 'regulars':
            return t('Regulars');
        case 'quiet':
            return t('Going quiet');
        case 'lost':
            return t('Lost');
        case 'new':
            return t('New');
    }
}

function lastVisit(t: TranslateFn, days: number): string {
    if (days === 0) {
        return t('Today');
    }

    if (days === 1) {
        return t('Yesterday');
    }

    return t(':count days ago', { count: days });
}

/** "ST" for "Salma T.": the first letter of each word. */
function initials(name: string): string {
    return name
        .split(/\s+/u)
        .filter((word) => word !== '')
        .map((word) => Array.from(word)[0] ?? '')
        .join('')
        .slice(0, 2);
}
