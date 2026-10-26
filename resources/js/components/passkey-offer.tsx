import { Link, router, usePage } from '@inertiajs/react';
import { usePasskeyRegister } from '@laravel/passkeys/react';
import { Fingerprint } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import PasskeyRegistration from '@/components/passkey-register';
import { buttonVariants } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { dismiss } from '@/routes/passkey-offer';

const subscribeToNothing = () => () => {};

/**
 * After an email-link sign-in (CHW-23): next time, Face ID or a fingerprint
 * instead of waiting for a link. Shown while the server offers it
 * (offerPasskey: no passkey yet, session confirmed), until they add one or
 * say "not now". In the server's HTML too, so the page never shifts when
 * it loads; only a browser that can't make passkeys hides it afterwards.
 * A landmark with a plain title, so the page's own heading comes first.
 */
export default function PasskeyOffer() {
    const { t } = useTranslation();
    const { offerPasskey } = usePage<{ offerPasskey: boolean }>().props;
    const { isSupported } = usePasskeyRegister();
    // False on the server and while hydrating, when isSupported is false too.
    const hydrated = useSyncExternalStore(
        subscribeToNothing,
        () => true,
        () => false,
    );

    if (!offerPasskey || (hydrated && !isSupported)) {
        return null;
    }

    return (
        <aside
            aria-labelledby="passkey-offer-title"
            className="mx-auto max-w-md px-4 pt-4"
            data-test="passkey-offer"
        >
            <div className="space-y-4 rounded-xl border bg-card p-4 text-card-foreground">
                <div className="flex gap-3">
                    <Fingerprint
                        aria-hidden="true"
                        className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    />
                    <div className="space-y-1">
                        <p id="passkey-offer-title" className="font-medium">
                            {t(
                                'Next time, sign in with Face ID or your fingerprint',
                            )}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'Add a passkey on this phone: no link to wait for.',
                            )}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap items-start gap-2">
                    {/* The whole page again: a passkey list below (security settings) shows the new one too. */}
                    <PasskeyRegistration onSuccess={() => router.reload()} />
                    <Link
                        href={dismiss()}
                        as="button"
                        preserveScroll
                        className={cn(buttonVariants({ variant: 'ghost' }))}
                        data-test="passkey-offer-dismiss"
                    >
                        {t('Not now')}
                    </Link>
                </div>
            </div>
        </aside>
    );
}
