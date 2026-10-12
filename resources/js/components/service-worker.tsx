import { useEffect } from 'react';
import { toast } from 'sonner';
import { useRegisterSW } from 'virtual:pwa-register/react';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Registers the service worker (CHW-35) on the pages that belong to an app
 * (a manifest is linked): when a new version is waiting, a toast offers to
 * reload, and only then does it take over, never mid-action.
 */
export default function ServiceWorker() {
    const { t } = useTranslation();
    const {
        needRefresh: [needRefresh],
        updateServiceWorker,
    } = useRegisterSW();

    useEffect(() => {
        if (!needRefresh) {
            return;
        }

        toast(t('A new version of punchcard is ready.'), {
            id: 'service-worker-update',
            duration: Infinity,
            action: {
                label: t('Reload'),
                onClick: () => void updateServiceWorker(true),
            },
        });
    }, [needRefresh, t, updateServiceWorker]);

    return null;
}
