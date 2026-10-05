import { Head, Link } from '@inertiajs/react';
import TapScreen, {
    TapCafe,
    TapLoyaltyCard,
} from '@/components/tap/tap-screen';
import type { TapCard } from '@/components/tap/tap-screen';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { login, register } from '@/routes';

/** C1: a signed-out first tap, its stamp waiting for sign-in (CHW-25). */
export default function TapPending({ card }: { card: TapCard | null }) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Your first stamp is waiting')} />
            <TapScreen
                actions={
                    <>
                        <Button asChild size="lg">
                            <Link href={register()}>
                                {t('Continue with email')}
                            </Link>
                        </Button>
                        <Button asChild size="lg" variant="outline">
                            <Link href={login()}>
                                {t('I already have an account')}
                            </Link>
                        </Button>
                        <p className="text-center text-sm text-muted-foreground">
                            {t('No app download needed.')}
                        </p>
                    </>
                }
            >
                {card ? <TapCafe card={card} /> : null}
                <h1 className="font-display text-4xl leading-tight font-bold text-balance">
                    {t('Your first stamp is waiting')}
                </h1>
                {card ? (
                    <TapLoyaltyCard
                        card={card}
                        ghostNext
                        progressLabel={t('Sign in to keep it')}
                    />
                ) : null}
            </TapScreen>
        </>
    );
}
