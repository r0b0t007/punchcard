import { Head } from '@inertiajs/react';
import OwnerDashboard from '@/components/dashboard/owner-dashboard';
import type { DashboardStats } from '@/components/dashboard/types';
import SetupChecklist from '@/components/setup-checklist';
import type { Checklist } from '@/components/setup-checklist';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';

/**
 * The dashboard: the setup checklist (CHW-31) and the owner dashboard
 * (CHW-33, B3 lite) for whoever runs the business. At the organization
 * level, a note: dashboards are per business until the network view (B18).
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

    return (
        <>
            <Head title={t('Dashboard')} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
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
