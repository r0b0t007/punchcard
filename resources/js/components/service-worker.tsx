import { useEffect } from 'react';
import { toast } from 'sonner';
import { useRegisterSW } from 'virtual:pwa-register/react';
import { useTranslation } from '@/hooks/use-translation';

const TOAST_ID = 'service-worker-update';

/**
 * Registers the service worker (CHW-35) once the page has loaded, so it
 * never competes with a tap or join page for the network. Mounted once, on
 * the first page of an app (a manifest is linked), and kept mounted: when a
 * new version is waiting, a toast on an app's pages offers to reload, and
 * only then does it take over, never mid-action.
 */
export default function ServiceWorker({ onAppPage }: { onAppPage: boolean }) {
    const { t } = useTranslation();
    const {
        needRefresh: [needRefresh],
        updateServiceWorker,
    } = useRegisterSW({ immediate: false });

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
                onClick: () => void updateServiceWorker(true),
            },
        });
    }, [needRefresh, onAppPage, t, updateServiceWorker]);

    return null;
}
