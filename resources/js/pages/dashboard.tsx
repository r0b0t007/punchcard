import { Head } from '@inertiajs/react';
import SetupChecklist from '@/components/setup-checklist';
import type { Checklist } from '@/components/setup-checklist';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';

export default function Dashboard({
    checklist,
}: {
    checklist: Checklist | null;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Dashboard')} />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                {checklist ? <SetupChecklist checklist={checklist} /> : null}
                <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full" />
                    </div>
                </div>
                <div className="relative min-h-screen flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    <PlaceholderPattern className="absolute inset-0 size-full" />
                </div>
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
