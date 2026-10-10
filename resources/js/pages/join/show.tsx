import { Form, Head, Link, usePage } from '@inertiajs/react';
import TapScreen, {
    TapCafe,
    TapLoyaltyCard,
} from '@/components/tap/tap-screen';
import type { TapCard } from '@/components/tap/tap-screen';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import {
    login as joinLogin,
    register as joinRegister,
    store,
} from '@/routes/join';
import { index as rewards } from '@/routes/rewards';
import type { Auth } from '@/types';

type Props = {
    slug: string;
    card: TapCard | null;
    available: boolean;
    joined: boolean;
};

/**
 * The join page (CHW-31), the QR stand's link: the business's card, to add
 * to your cards. No stamp here: the first one comes with a tap at the
 * counter.
 */
export default function JoinShow({ slug, card, available, joined }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<{ auth: Partial<Auth> }>().props;
    const signedIn = auth.user !== undefined && auth.user !== null;

    const actions = !available ? null : joined ? (
        <>
            <p className="text-center font-medium">
                {t('This card is on your cards.')}
            </p>
            <Button asChild size="lg" variant="outline">
                <Link href={rewards()}>{t('My rewards')}</Link>
            </Button>
        </>
    ) : signedIn ? (
        <Form {...store.form(slug)} className="flex flex-col">
            {({ processing }) => (
                <Button type="submit" size="lg" disabled={processing}>
                    {t('Add to my cards')}
                </Button>
            )}
        </Form>
    ) : (
        <>
            <Button asChild size="lg">
                <Link href={joinRegister(slug)}>
                    {t('Continue with email')}
                </Link>
            </Button>
            <Button asChild size="lg" variant="outline">
                <Link href={joinLogin(slug)}>
                    {t('I already have an account')}
                </Link>
            </Button>
            <p className="text-center text-sm text-muted-foreground">
                {t('No app download needed.')}
            </p>
        </>
    );

    return (
        <>
            <Head title={card?.businessName ?? t('Loyalty card')} />
            <TapScreen actions={actions}>
                {card ? <TapCafe card={card} /> : null}
                <h1 className="font-display text-4xl leading-tight font-bold text-balance">
                    {available
                        ? t('Collect stamps here')
                        : t('This card is not available yet')}
                </h1>
                {card ? <TapLoyaltyCard card={card} /> : null}
                {available ? (
                    <p className="text-muted-foreground">
                        {t(
                            'Tap the stamper at the counter to get your stamps.',
                        )}
                    </p>
                ) : null}
            </TapScreen>
        </>
    );
}
