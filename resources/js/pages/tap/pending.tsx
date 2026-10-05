import { Head, Link } from '@inertiajs/react';
import TapScreen from '@/components/tap/tap-screen';
import type { TapCard } from '@/components/tap/tap-screen';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { login, register } from '@/routes';

/** C1: a signed-out first tap, waiting for sign-in (CHW-25). */
export default function TapPending({ card }: { card: TapCard | null }) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Your first stamp is waiting')} />
            <TapScreen title={t('Your first stamp is waiting')} card={card}>
                <p className="text-muted-foreground">
                    {t(
                        'Sign in or create an account to keep it. No app to download.',
                    )}
                </p>
                <Button asChild size="lg">
                    <Link href={register()}>{t('Create an account')}</Link>
                </Button>
                <Button asChild size="lg" variant="outline">
                    <Link href={login()}>{t('Log in')}</Link>
                </Button>
            </TapScreen>
        </>
    );
}
