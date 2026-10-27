import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTranslation } from '@/hooks/use-translation';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editPrivacy } from '@/routes/privacy';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: null,
    },
    {
        title: 'Privacy',
        href: editPrivacy(),
        icon: null,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { t } = useTranslation();

    return (
        <div className="px-6 pt-5 pb-6">
            <Heading
                as="h1"
                title={t('Settings')}
                description={t('Manage your profile and account settings')}
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    {/* C12 (Claude Design `CH Settings`, CHW-217): 40px items at radius 10, the current one on the secondary surface. */}
                    <nav
                        className="flex flex-col gap-1"
                        aria-label={t('Settings')}
                    >
                        {sidebarNavItems.map((item, index) => {
                            const current = isCurrentOrParentUrl(item.href);

                            return (
                                <Link
                                    key={`${toUrl(item.href)}-${index}`}
                                    href={item.href}
                                    aria-current={current ? 'page' : undefined}
                                    className={cn(
                                        'flex min-h-10 items-center gap-2 rounded-sm px-3 py-2 text-md transition-colors focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none',
                                        // The hover is lighter than the current page's surface, which is the same colour.
                                        current
                                            ? 'bg-secondary font-semibold text-secondary-foreground'
                                            : 'hover:bg-accent/50 hover:text-accent-foreground',
                                    )}
                                >
                                    {item.icon && (
                                        <item.icon className="h-4 w-4" />
                                    )}
                                    {t(item.title)}
                                </Link>
                            );
                        })}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
