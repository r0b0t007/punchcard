import { Head } from '@inertiajs/react';
import TapScreen from '@/components/tap/tap-screen';
import { useTranslation } from '@/hooks/use-translation';

type Reason = 'used' | 'expired' | 'limit' | 'unavailable' | 'card' | 'invalid';

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
        invalid: t('This tap could not be read. Touch the stamper again.'),
    };

    return (
        <>
            <Head title={t('No stamp this time')} />
            <TapScreen title={t('No stamp this time')}>
                <p className="text-muted-foreground">{messages[reason]}</p>
            </TapScreen>
        </>
    );
}
