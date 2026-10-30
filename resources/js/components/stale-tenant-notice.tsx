import { router, usePage } from '@inertiajs/react';
import { ArrowLeftRight } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { hearTenant, TENANT_CHANNEL } from '@/lib/tenant-guard';
import type { ShownTenant } from '@/lib/tenant-guard';
import { dashboard } from '@/routes';

/**
 * A tab left on another business (CHW-147, design 10f-2 in Punchcard Stale
 * Tab Review). Each business page tells the app's other tabs (one browser,
 * a BroadcastChannel) where it works and when it was rendered; the older
 * page, a tab left behind or one put back from history, dims itself under
 * this notice before anything is typed there, and offers the business
 * worked in now. Everything else on the page, an open dialog too, is inert
 * meanwhile. A save that slips through anyway is refused by the server
 * (EnsureTenantUnchanged), with a toast.
 */
export default function StaleTenantNotice() {
    const { tenant } = usePage().props;
    const { t, tn } = useTranslation();
    // Where another tab works, as heard while this one worked in `on`: a switch here makes it old news.
    const [heard, setHeard] = useState<{
        on: string;
        tenant: ShownTenant;
    } | null>(null);
    const latest = useRef(tenant);
    const choice = tenant?.choice ?? null;

    useLayoutEffect(() => {
        latest.current = tenant;
    }, [tenant]);

    // Once per tenant, not per visit or rename: the channel stays open meanwhile.
    useEffect(() => {
        if (choice === null || typeof BroadcastChannel === 'undefined') {
            return;
        }

        const channel = new BroadcastChannel(TENANT_CHANNEL);
        channel.onmessage = (event: MessageEvent<ShownTenant>) => {
            const own = latest.current;

            if (!own) {
                return;
            }

            const heardNow = hearTenant(own, event.data);

            if (heardNow === 'stale') {
                setHeard({ on: own.choice, tenant: event.data });
            } else if (heardNow === 'tell') {
                channel.postMessage(own);
            }
        };

        if (latest.current) {
            channel.postMessage(latest.current);
        }

        return () => channel.close();
    }, [choice]);

    const elsewhere =
        heard !== null && heard.on === choice && heard.tenant.choice !== choice
            ? heard.tenant
            : null;

    // The notice last in <body>, above any open dialog, and the rest inert under it.
    const [host] = useState(() =>
        typeof document === 'undefined' ? null : document.createElement('div'),
    );

    useLayoutEffect(() => {
        if (elsewhere === null || host === null) {
            return;
        }

        document.body.appendChild(host);
        const others = Array.from(document.body.children).filter(
            (element) => element !== host && !element.hasAttribute('inert'),
        );
        others.forEach((element) => element.setAttribute('inert', ''));

        return () => {
            others.forEach((element) => element.removeAttribute('inert'));
            host.remove();
        };
    }, [elsewhere, host]);

    if (elsewhere === null || host === null) {
        return null;
    }

    const name = tenant?.name ?? null;

    return createPortal(
        <div
            className="fixed inset-0 z-50 flex items-start justify-center bg-background/70 p-4 print:hidden"
            data-test="stale-tenant"
        >
            <div
                role="alert"
                className="mt-14 flex w-full max-w-md flex-col items-start gap-3 rounded-2xl border border-warning bg-card p-4 text-sm shadow-lg"
            >
                <div className="flex gap-2.5">
                    <ArrowLeftRight
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden
                    />
                    <p>
                        {elsewhere.name
                            ? tn('You switched to :business in another tab.', {
                                  business: <bdi>{elsewhere.name}</bdi>,
                              })
                            : t('You switched business in another tab.')}{' '}
                        {name
                            ? tn(
                                  "This page is still :business's: nothing here can be saved.",
                                  { business: <bdi>{name}</bdi> },
                              )
                            : t('Nothing here can be saved.')}
                    </p>
                </div>
                <Button
                    variant="outline"
                    onClick={() => router.visit(dashboard())}
                    data-test="stale-tenant-show"
                >
                    <span>
                        {elsewhere.name
                            ? tn('Show :business', {
                                  business: <bdi>{elsewhere.name}</bdi>,
                              })
                            : t('Dashboard')}
                    </span>
                </Button>
            </div>
        </div>,
        host,
    );
}
