import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useRegisterSW } from 'virtual:pwa-register/react';
import { useTranslation } from '@/hooks/use-translation';
import { loadInFullWhenOffline } from '@/lib/offline-visits';
import { createUpdateFlow } from '@/lib/update-flow';

const TOAST_ID = 'service-worker-update';

/**
 * Registers the service worker (CHW-35) once the page has loaded, so it
 * never competes with a tap or join page for the network. Mounted once, on
 * the first page of an app (a manifest is linked), and kept mounted: when a
 * new version is waiting, a toast on an app's pages offers to reload, and
 * only the tab whose Reload was tapped reloads (createUpdateFlow).
 * Offline, a visit to My cards loads in full, for the worker to show the
 * cards kept on the phone (loadInFullWhenOffline).
 */
export default function ServiceWorker({ onAppPage }: { onAppPage: boolean }) {
    const { t } = useTranslation();
    const activate = useRef<() => void>(() => undefined);
    const [flow] = useState(() =>
        createUpdateFlow(
            () => window.location.reload(),
            () => activate.current(),
        ),
    );
    const {
        needRefresh: [needRefresh],
        updateServiceWorker,
    } = useRegisterSW({
        immediate: false,
        // In place of vite-plugin-pwa's reload of every tab the new version takes over.
        onNeedReload: () => flow.newVersionControls(),
    });

    useEffect(() => {
        activate.current = () => void updateServiceWorker(true);
    }, [updateServiceWorker]);

    useEffect(() => loadInFullWhenOffline(), []);

    useEffect(() => {
        if (!needRefresh || !onAppPage) {
            toast.dismiss(TOAST_ID);

            return;
        }

        toast(t('A new version of punchcard is ready.'), {
            id: TOAST_ID,
            duration: Infinity,
            action: {
                label: t('Reload'),
                onClick: () => flow.requestReload(),
            },
        });
    }, [flow, needRefresh, onAppPage, t]);

    return null;
}
