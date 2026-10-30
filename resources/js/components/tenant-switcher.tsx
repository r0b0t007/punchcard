import { Link, router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Network } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { StampDot } from '@/components/wordmark';
import { useIsMobile } from '@/hooks/use-mobile';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { update } from '@/routes/tenant';
import type { TenantEntry } from '@/types/tenants';

/** Works in the business or network chosen, then opens its dashboard (TenantController). */
export function switchTenant(choice: string) {
    router.put(update().url, { choice });
}

/**
 * A business's mark: its organization's logo, else its initial on an
 * espresso tile; a network's icon; the punchcard dot when none is chosen.
 */
export function TenantMark({ entry }: { entry: TenantEntry | null }) {
    if (entry === null) {
        return (
            <span className="flex size-8 shrink-0 items-center justify-center rounded-sm bg-sidebar-primary">
                <StampDot halo={false} className="size-3" />
            </span>
        );
    }

    if (entry.kind === 'network') {
        return (
            <span className="flex size-8 shrink-0 items-center justify-center rounded-sm bg-sidebar-accent text-sidebar-accent-foreground">
                <Network className="size-4" aria-hidden />
            </span>
        );
    }

    if (entry.logoUrl) {
        return (
            <img
                src={entry.logoUrl}
                alt=""
                className="size-8 shrink-0 rounded-sm object-cover"
            />
        );
    }

    return (
        <span
            className="flex size-8 shrink-0 items-center justify-center rounded-sm bg-sidebar-primary font-display text-base font-bold text-sidebar-primary-foreground"
            aria-hidden
        >
            {entry.initial}
        </span>
    );
}

/** Its name and the user's role there, or "Switch business" when none is chosen (the dashboard asks the question, 10d). */
function TenantLabel({ entry }: { entry: TenantEntry | null }) {
    const { t } = useTranslation();

    return (
        <div className="grid flex-1 text-start leading-tight">
            <span className="truncate text-md font-semibold">
                <bdi>{entry?.name ?? t('Switch business')}</bdi>
            </span>
            {entry ? (
                <span className="truncate text-xs text-muted-foreground">
                    {entry.role}
                </span>
            ) : null}
        </div>
    );
}

/**
 * The sidebar's header (CHW-147, design 10a): the business worked in, its
 * mark and the user's role there. With more than one to choose from, a
 * menu lists them all by organization, the current one ticked; choosing
 * one opens its dashboard. With one, it links to the dashboard.
 */
export function TenantSwitcher() {
    const { tenants } = usePage().props;
    const { t } = useTranslation();
    const { state } = useSidebar();
    const isMobile = useIsMobile();
    const cleanup = useMobileNavigation();
    const current = tenants?.current ?? null;
    const choices = tenants?.groups.flatMap((group) => group.entries) ?? [];

    if (tenants === null || choices.length <= 1) {
        return (
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        asChild
                        tooltip={current?.name}
                    >
                        <Link href={dashboard()} prefetch>
                            <TenantMark entry={current} />
                            <TenantLabel entry={current} />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        );
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            variant="trigger"
                            tooltip={current?.name ?? t('Switch business')}
                            data-test="tenant-switcher"
                        >
                            <TenantMark entry={current} />
                            <TenantLabel entry={current} />
                            {current ? (
                                <span className="sr-only">
                                    {t('Switch business')}
                                </span>
                            ) : null}
                            <ChevronsUpDown className="ms-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-72"
                        align="start"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'right'
                                  : 'bottom'
                        }
                    >
                        {tenants.groups.map((group, index) => (
                            <DropdownMenuGroup key={group.organizationId}>
                                {index > 0 ? <DropdownMenuSeparator /> : null}
                                <div className="px-2 pt-2 pb-1 text-xs font-semibold text-muted-foreground">
                                    <bdi>{group.organization}</bdi>
                                </div>
                                {group.entries.map((entry) => (
                                    <DropdownMenuItem
                                        key={entry.choice}
                                        asChild
                                    >
                                        <button
                                            type="button"
                                            className="flex min-h-12 w-full cursor-pointer items-center gap-2.5 text-start"
                                            aria-current={
                                                entry.current
                                                    ? 'true'
                                                    : undefined
                                            }
                                            onClick={() => {
                                                cleanup();

                                                if (!entry.current) {
                                                    switchTenant(entry.choice);
                                                }
                                            }}
                                        >
                                            <TenantMark entry={entry} />
                                            <span className="grid flex-1 leading-tight">
                                                <span className="truncate font-medium">
                                                    <bdi>{entry.name}</bdi>
                                                </span>
                                                <span className="truncate text-xs text-muted-foreground">
                                                    {entry.role}
                                                </span>
                                            </span>
                                            {entry.current ? (
                                                <Check
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                            ) : null}
                                        </button>
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuGroup>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
