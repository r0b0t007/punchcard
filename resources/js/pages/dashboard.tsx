import { Head, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import OwnerDashboard from '@/components/dashboard/owner-dashboard';
import type { DashboardStats } from '@/components/dashboard/types';
import SetupChecklist from '@/components/setup-checklist';
import type { Checklist } from '@/components/setup-checklist';
import { switchTenant, TenantMark } from '@/components/tenant-switcher';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';

/**
 * The dashboard: the setup checklist (CHW-31) and the owner dashboard
 * (CHW-33, B3 lite) for whoever runs the business. At the organization
 * level, a note: dashboards are per business until the network view (B18).
 * With no business chosen (CHW-147, design 10d): where do you work today,
 * one row per business or network, as the sidebar's menu lists them.
 */
export default function Dashboard({
    checklist,
    stats,
    atOrganization,
}: {
    checklist: Checklist | null;
    stats: DashboardStats | null;
    atOrganization: boolean;
}) {
    const { t } = useTranslation();
    const { tenants } = usePage().props;
    const choosing = tenants !== null && tenants.current === null;

    return (
        <>
            <Head title={t('Dashboard')} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {choosing ? (
                    <section className="flex max-w-xl flex-col gap-4">
                        <div className="space-y-1">
                            <h1 className="font-display text-title font-bold">
                                {t('Where do you work today?')}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'Choose a business. You can change it any time from the menu.',
                                )}
                            </p>
                        </div>
                        <ul className="flex flex-col gap-2.5">
                            {tenants.groups.flatMap((group) =>
                                group.entries.map((entry) => (
                                    <li key={entry.choice}>
                                        <button
                                            type="button"
                                            className="flex w-full items-center gap-3 rounded-2xl border bg-card px-4 py-3.5 text-start transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            onClick={() =>
                                                switchTenant(entry.choice)
                                            }
                                        >
                                            <TenantMark entry={entry} />
                                            <span className="grid flex-1 gap-0.5 leading-tight">
                                                <span className="truncate text-md font-semibold">
                                                    <bdi>{entry.name}</bdi>
                                                </span>
                                                <span className="truncate text-sm text-muted-foreground">
                                                    <bdi>
                                                        {group.organization}
                                                    </bdi>
                                                    {' · '}
                                                    {entry.role}
                                                </span>
                                            </span>
                                            <ChevronRight
                                                className="size-4 rtl:rotate-180"
                                                aria-hidden
                                            />
                                        </button>
                                    </li>
                                )),
                            )}
                        </ul>
                    </section>
                ) : null}
                {checklist ? <SetupChecklist checklist={checklist} /> : null}
                {atOrganization ? (
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Each business has its own dashboard. A view of your whole network is coming.',
                        )}
                    </p>
                ) : null}
                {stats ? <OwnerDashboard stats={stats} /> : null}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
