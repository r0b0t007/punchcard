import { Head, Link } from '@inertiajs/react';
import TapScreen from '@/components/tap/tap-screen';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { home } from '@/routes';

type Reason =
    | 'used'
    | 'expired'
    | 'limit'
    | 'unavailable'
    | 'card'
    | 'redeemed'
    | 'deleted'
    | 'invalid'
    | 'busy'
    | 'retry';

/** A tap that gave no stamp, with a friendly reason; the detail stays in the tap log (CHW-25). */
export default function TapRefused({ reason }: { reason: Reason }) {
    const { t } = useTranslation();
    const messages: Record<Reason, string> = {
        used: t('This tap was already used. Touch the stamper again.'),
        expired: t('This tap expired. Touch the stamper again.'),
        limit: t("You reached today's stamp limit here. See you tomorrow."),
        unavailable: t(
            'This stamper is not available right now. Ask the staff.',
        ),
        card: t("This café's card is not available right now."),
        redeemed: t('This reward was already redeemed.'),
        deleted: t('This account was deleted, so it collects no more stamps.'),
        invalid: t('This tap could not be read. Touch the stamper again.'),
        busy: t(
            'Too many taps right now. Wait a moment, then touch the stamper again.',
        ),
        retry: t(
            'We could not save your stamp just now. Reload this page to try again.',
        ),
    };

    return (
        <>
            <Head title={t('No stamp this time')} />
            <TapScreen
                actions={
                    <Button asChild size="lg">
                        <Link href={home()}>{t('Done')}</Link>
                    </Button>
                }
            >
                {/* The reason sits under the title, closer than the frame's blocks (Claude Design `CH Refused`). */}
                <div className="flex flex-col gap-3.5">
                    <h1 className="font-display text-4xl leading-tight font-bold text-balance">
                        {t('No stamp this time')}
                    </h1>
                    <p className="text-lg text-pretty text-muted-foreground">
                        {messages[reason]}
                    </p>
                </div>
            </TapScreen>
        </>
    );
}
