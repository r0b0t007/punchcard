import { Link, usePage } from '@inertiajs/react';
import { Gift, UserRound, WalletCards } from 'lucide-react';
import type { ReactNode } from 'react';
import PasskeyOffer from '@/components/passkey-offer';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { cards } from '@/routes/app';
import { edit as profile } from '@/routes/profile';
import { index as rewards } from '@/routes/rewards';

/** The customer's tabs (C5's bottom navigation); Discover comes with discovery (CHW-54). */
const TABS = [
    { label: 'Cards', href: cards(), section: '/app', icon: WalletCards },
    { label: 'Rewards', href: rewards(), section: '/rewards', icon: Gift },
    {
        label: 'Profile',
        href: profile(),
        section: '/settings',
        icon: UserRound,
    },
] as const;

/**
 * The customer's screens (CHW-27): full screen, with a tab bar pinned to the
 * bottom, and the passkey offered after an email-link sign-in (CHW-23).
 */
export default function CustomerLayout({ children }: { children: ReactNode }) {
    const { t } = useTranslation();
    const { url } = usePage();
    const path = url.split('?')[0];

    return (
        <div className="min-h-dvh bg-background pb-20">
            <PasskeyOffer />
            {children}
            <nav
                aria-label={t('Main')}
                className="fixed inset-x-0 bottom-0 z-10 border-t bg-background"
            >
                <ul className="mx-auto grid max-w-md grid-cols-3">
                    {TABS.map((tab) => {
                        const current =
                            path === tab.section ||
                            path.startsWith(`${tab.section}/`);

                        return (
                            <li key={tab.label}>
                                <Link
                                    href={tab.href}
                                    aria-current={current ? 'page' : undefined}
                                    className={cn(
                                        'flex h-16 flex-col items-center justify-center gap-1 text-xs font-medium',
                                        current
                                            ? 'text-foreground'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    <tab.icon
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                    {t(tab.label)}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </nav>
        </div>
    );
}
