import { Link, router, usePage } from '@inertiajs/react';
import { usePasskeyRegister } from '@laravel/passkeys/react';
import { Fingerprint } from 'lucide-react';
import PasskeyRegistration from '@/components/passkey-register';
import { buttonVariants } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { dismiss } from '@/routes/passkey-offer';

/**
 * After an email-link sign-in (CHW-23): next time, Face ID or a fingerprint
 * instead of waiting for a link. Shown while the server offers it
 * (offerPasskey: no passkey yet, session confirmed), in a browser that can
 * make one, until they add one or say "not now".
 */
export default function PasskeyOffer() {
    const { t } = useTranslation();
    const { offerPasskey } = usePage<{ offerPasskey: boolean }>().props;
    const { isSupported } = usePasskeyRegister();

    if (!offerPasskey || !isSupported) {
        return null;
    }

    return (
        <section
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
                        <h2 id="passkey-offer-title" className="font-medium">
                            {t(
                                'Next time, sign in with Face ID or your fingerprint',
                            )}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'Add a passkey on this phone: no link to wait for.',
                            )}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap items-start gap-2">
                    <PasskeyRegistration
                        onSuccess={() =>
                            router.reload({ only: ['offerPasskey'] })
                        }
                    />
                    <Link
                        href={dismiss()}
                        as="button"
                        preserveScroll
                        className={buttonVariants({ variant: 'ghost' })}
                        data-test="passkey-offer-dismiss"
                    >
                        {t('Not now')}
                    </Link>
                </div>
            </div>
        </section>
    );
}
