import { Link, usePage } from '@inertiajs/react';
import { CreditCard, LayoutGrid, Nfc, Stamp, Users } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as cards } from '@/routes/cards';
import { show as counter } from '@/routes/counter';
import { index as staff } from '@/routes/staff';
import { index as stampers } from '@/routes/stampers';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const cardsNavItem: NavItem = {
    title: 'Cards',
    href: cards(),
    icon: CreditCard,
};

const counterNavItem: NavItem = {
    title: 'Counter mode',
    href: counter(),
    icon: Nfc,
};

const stampersNavItem: NavItem = {
    title: 'Stampers',
    href: stampers(),
    icon: Stamp,
};

const staffNavItem: NavItem = {
    title: 'Team',
    href: staff(),
    icon: Users,
};

export function AppSidebar() {
    const { nav } = usePage().props;
    const items = [
        ...mainNavItems,
        ...(nav.counter ? [counterNavItem] : []),
        ...(nav.cards ? [cardsNavItem] : []),
        ...(nav.stampers ? [stampersNavItem] : []),
        ...(nav.staff ? [staffNavItem] : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
